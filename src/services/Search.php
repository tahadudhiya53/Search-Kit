<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\base\ElementInterface;
use Tahadudhiya\SearchKit\base\SearchProviderInterface;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\errors\IndexDisabledException;
use Tahadudhiya\SearchKit\errors\IndexNotFoundException;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\errors\SearchKitException;
use Tahadudhiya\SearchKit\errors\UnauthorizedQueryException;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\events\SearchEvent;
use Tahadudhiya\SearchKit\models\SearchHit;
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

    /** @var string[] Statuses Craft understands for every element type, on top of its own. */
    private const COMMON_STATUSES = ['enabled', 'disabled', 'archived'];

    private ?Indexes $_indexes = null;
    private ?SearchableFields $_searchableFields = null;
    private ?Providers $_providers = null;
    private ?Highlighting $_highlighting = null;

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
        $this->assertStatusIsKnown($query, $index);
        $this->assertStatusIsViewable($query, $index);

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

        $this->normalizeHitSites($result, $query, $index);
        $this->hydrateElements($result, $query);
        $this->assertResultsAreViewable($result, $query, $index);

        // A provider that highlights for itself keeps its own answer; this fills in for one that
        // cannot, so highlighting is part of the API whatever is serving the index.
        if ($query->highlight && !$provider->supports(ProviderCapability::Highlighting)) {
            $this->getHighlighting()->apply($result, $query, $index);
        }

        $this->trigger(self::EVENT_AFTER_SEARCH, new SearchEvent([
            'query' => $query,
            'index' => $index,
            'result' => $result,
        ]));

        return $result;
    }

    /**
     * Every hit names the site it was found in, so nothing downstream has to guess which variant
     * of an element it holds. A provider that cannot say inherits the scope the search ran in.
     *
     * @throws ProviderException
     */
    private function normalizeHitSites(SearchResult $result, SearchQuery $query, SearchIndex $index): void
    {
        foreach ($result->hits as $hit) {
            $hit->siteId ??= $query->siteId ?? $index->siteId;

            if ($hit->siteId === null) {
                throw new ProviderException("A search of “{$index->handle}” returned a result without a site.");
            }

            // A provider may only answer within the scope it was given; it can never widen it.
            $outOfScope = $query->siteId !== null ? $hit->siteId !== $query->siteId : !$index->coversSite($hit->siteId);

            if ($outOfScope) {
                throw new ProviderException("A search of “{$index->handle}” returned a result from outside its site scope.");
            }
        }
    }

    /**
     * Loads the element behind every hit a provider identified but did not resolve, so a result
     * carries elements whatever the provider returns. One query per element type and site.
     */
    private function hydrateElements(SearchResult $result, SearchQuery $query): void
    {
        $missing = [];

        foreach ($result->hits as $hit) {
            if ($hit->element === null && $hit->elementType !== null && is_subclass_of($hit->elementType, ElementInterface::class)) {
                $missing[$hit->elementType][(int)$hit->siteId][] = $hit;
            }
        }

        foreach ($missing as $elementType => $hitsBySite) {
            foreach ($hitsBySite as $siteId => $hits) {
                $this->hydrate($elementType, $siteId, $hits, $query);
            }
        }

        $this->dropHitsWithoutElements($result);
    }

    /**
     * @param class-string<ElementInterface> $elementType
     * @param SearchHit[] $hits
     */
    private function hydrate(string $elementType, int $siteId, array $hits, SearchQuery $query): void
    {
        try {
            // The site is exact and the status is the one searched for, so hydrating can neither
            // reach another site's variant nor turn up content the search itself excluded.
            $elementQuery = $elementType::find()
                ->id(array_map(static fn(SearchHit $hit) => $hit->elementId, $hits))
                ->siteId($siteId)
                ->indexBy('id');

            if ($query->status !== null) {
                $elementQuery->status($query->status);
            }

            $elements = $elementQuery->all();
        } catch (Throwable $e) {
            // The hits still identify their elements, so this is reported rather than fatal.
            Craft::warning("Could not load {$elementType} elements for a search result: {$e->getMessage()}", SearchKit::LOG_CATEGORY);
            return;
        }

        foreach ($hits as $hit) {
            $hit->element = $elements[$hit->elementId] ?? null;
        }
    }

    /**
     * A hit whose element cannot be loaded in the searched site and status is not a result anyone
     * may see, so it is dropped rather than returned as an identifier with nothing behind it.
     */
    private function dropHitsWithoutElements(SearchResult $result): void
    {
        $this->keepHits($result, array_values(array_filter(
            $result->hits,
            static fn(SearchHit $hit) => $hit->element !== null,
        )));
    }

    /**
     * A status Craft cannot build a condition for would quietly match nothing at all, so it is
     * refused instead.
     *
     * @throws InvalidQueryException
     */
    private function assertStatusIsKnown(SearchQuery $query, SearchIndex $index): void
    {
        if ($query->status === null) {
            return;
        }

        foreach ($index->getElementTypes() as $elementType) {
            /** @var class-string<ElementInterface> $elementType */
            if ($this->statusApplies($query->status, $elementType)) {
                return;
            }
        }

        throw new InvalidQueryException(
            "“{$query->status}” is not a status this search index can be asked for.",
            ['status' => ["No element type in this index has a “{$query->status}” status."]],
        );
    }

    /**
     * Anything other than published content is searchable only by a signed-in administrator, in a
     * request or out of one. Authorization is decided before the search runs rather than by dropping
     * results afterwards, which would leave the total counting what was withheld.
     *
     * @throws UnauthorizedQueryException
     */
    private function assertStatusIsViewable(SearchQuery $query, SearchIndex $index): void
    {
        if ($this->isPublishedStatus($query->status, $index)) {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || !$user->admin) {
            throw new UnauthorizedQueryException("Only published content can be searched by status “{$query->status}”.");
        }
    }

    /**
     * Craft's own answer is honoured for every result of an unpublished search. An administrator
     * passes it unless the site refuses them, and a refusal makes the whole search unanswerable:
     * dropping the result here would leave the total describing something else.
     *
     * @throws UnauthorizedQueryException
     */
    private function assertResultsAreViewable(SearchResult $result, SearchQuery $query, SearchIndex $index): void
    {
        if ($this->isPublishedStatus($query->status, $index)) {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return;
        }

        $elements = Craft::$app->getElements();

        foreach ($result->hits as $hit) {
            if ($hit->element !== null && !$elements->canView($hit->element, $user)) {
                throw new UnauthorizedQueryException('This search returned content you are not allowed to view.');
            }
        }
    }

    /**
     * Whether a status is what Craft itself publishes: the status an unrestricted query of each
     * element type returns. For entries that is `live` alone — `enabled` also covers content that
     * is scheduled or has expired.
     */
    private function isPublishedStatus(?string $status, SearchIndex $index): bool
    {
        if ($status === null) {
            return true;
        }

        foreach ($index->getElementTypes() as $elementType) {
            /** @var class-string<ElementInterface> $elementType */
            if (!$this->statusApplies($status, $elementType)) {
                // Craft answers this element type with nothing at all, so it can publish nothing.
                continue;
            }

            if (!in_array($status, (array)$elementType::find()->status, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether Craft can build a condition for this status on this element type.
     *
     * @param class-string<ElementInterface> $elementType
     */
    private function statusApplies(string $status, string $elementType): bool
    {
        return in_array($status, [...array_keys($elementType::statuses()), ...self::COMMON_STATUSES], true);
    }

    /**
     * Keeps the given hits, taking whatever was removed off the provider's total with them.
     *
     * @param SearchHit[] $hits
     */
    private function keepHits(SearchResult $result, array $hits): void
    {
        $removed = count($result->hits) - count($hits);

        if ($removed > 0) {
            $result->hits = $hits;
            $result->total = max(count($hits), $result->total - $removed);
        }
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

    public function setHighlighting(Highlighting $highlighting): void
    {
        $this->_highlighting = $highlighting;
    }

    public function getHighlighting(): Highlighting
    {
        return $this->_highlighting ??= $this->plugin()->getHighlighting();
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
