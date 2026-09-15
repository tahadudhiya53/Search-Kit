<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use Tahadudhiya\SearchKit\base\SearchProviderInterface;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\errors\IndexDisabledException;
use Tahadudhiya\SearchKit\errors\IndexNotFoundException;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\errors\SearchKitException;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\events\SearchEvent;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Runs a search query through the index's provider and returns a normalized result.
 */
class Search extends Component
{
    public const EVENT_BEFORE_SEARCH = 'beforeSearch';
    public const EVENT_AFTER_SEARCH = 'afterSearch';

    private ?Indexes $_indexes = null;
    private ?SearchableFields $_searchableFields = null;
    private ?Providers $_providers = null;

    /**
     * @throws InvalidQueryException|IndexNotFoundException|IndexDisabledException|ProviderException
     */
    public function search(SearchQuery $query): SearchResult
    {
        if (!$query->validate()) {
            throw new InvalidQueryException('The search query is not valid.', $query->getErrors());
        }

        $index = $this->resolveIndex($query->indexHandle);
        $this->getSearchableFields()->attachFields($index);

        $this->assertQuerySiteIsUsable($query, $index);

        $provider = $this->getProviders()->getProviderForIndex($index);
        $this->assertProviderCanRun($query, $provider);

        $this->trigger(self::EVENT_BEFORE_SEARCH, new SearchEvent([
            'query' => $query,
            'index' => $index,
        ]));

        $startedAt = hrtime(true);

        try {
            $result = $provider->search($query, $index);
        } catch (SearchKitException $e) {
            throw $e;
        } catch (Throwable $e) {
            Craft::error("Search failed on “{$index->handle}”: {$e->getMessage()}", SearchKit::LOG_CATEGORY);
            throw new ProviderException("The “{$index->name}” search index could not be searched.", 0, $e);
        }

        // Normalizing these here is what makes every result comparable, whatever the provider did.
        $result->executionTime = (hrtime(true) - $startedAt) / 1_000_000;
        $result->indexHandle = $index->handle;
        $result->provider = $provider::class;
        $result->limit = $query->limit;
        $result->offset = $query->offset;

        $this->trigger(self::EVENT_AFTER_SEARCH, new SearchEvent([
            'query' => $query,
            'index' => $index,
            'result' => $result,
        ]));

        return $result;
    }

    /**
     * @throws IndexNotFoundException|IndexDisabledException
     */
    public function resolveIndex(string $handle): SearchIndex
    {
        $index = $this->getIndexes()->getIndexByHandle($handle);

        if ($index === null) {
            throw new IndexNotFoundException("No search index exists with the handle “{$handle}”.");
        }

        if (!$index->enabled) {
            throw new IndexDisabledException("The “{$index->name}” search index is disabled.");
        }

        return $index;
    }

    /**
     * An index declares the sites it covers, so a query may only narrow that scope, never widen it.
     *
     * @throws InvalidQueryException
     */
    private function assertQuerySiteIsUsable(SearchQuery $query, SearchIndex $index): void
    {
        if ($query->siteId === null) {
            return;
        }

        // Disabled sites still count: Craft can search them, so SearchKit does not add a stricter rule.
        if (Craft::$app->getSites()->getSiteById($query->siteId, true) === null) {
            throw new InvalidQueryException(
                'The requested site does not exist.',
                ['siteId' => ["No site exists with the ID {$query->siteId}."]],
            );
        }

        if (!$index->coversSite($query->siteId)) {
            throw new InvalidQueryException(
                "The “{$index->name}” search index does not cover the requested site.",
                ['siteId' => ["Site {$query->siteId} is outside this index's scope."]],
            );
        }
    }

    /**
     * Capabilities are checked here so a query is never silently downgraded by a provider.
     *
     * @throws UnsupportedCapabilityException
     */
    private function assertProviderCanRun(SearchQuery $query, SearchProviderInterface $provider): void
    {
        $name = $provider::displayName();

        if (!$provider->supports(ProviderCapability::Search)) {
            throw UnsupportedCapabilityException::for($name, ProviderCapability::Search);
        }

        if ($query->getFilters() !== [] && !$provider->supports(ProviderCapability::Filtering)) {
            throw UnsupportedCapabilityException::for($name, ProviderCapability::Filtering);
        }

        if ($query->hasCustomSort() && !$provider->supports(ProviderCapability::Sorting)) {
            throw UnsupportedCapabilityException::for($name, ProviderCapability::Sorting);
        }
    }

    public function setIndexes(Indexes $indexes): void
    {
        $this->_indexes = $indexes;
    }

    public function getIndexes(): Indexes
    {
        return $this->_indexes ??= $this->plugin()->getIndexes();
    }

    public function setSearchableFields(SearchableFields $searchableFields): void
    {
        $this->_searchableFields = $searchableFields;
    }

    public function getSearchableFields(): SearchableFields
    {
        return $this->_searchableFields ??= $this->plugin()->getSearchableFields();
    }

    public function setProviders(Providers $providers): void
    {
        $this->_providers = $providers;
    }

    public function getProviders(): Providers
    {
        return $this->_providers ??= $this->plugin()->getProviders();
    }

    private function plugin(): SearchKit
    {
        $plugin = SearchKit::getInstance();

        if ($plugin === null) {
            throw new InvalidConfigException('SearchKit is not installed or is disabled.');
        }

        return $plugin;
    }
}
