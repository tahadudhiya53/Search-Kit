<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\base\ElementInterface;
use Tahadudhiya\SearchKit\errors\IndexDisabledException;
use Tahadudhiya\SearchKit\errors\IndexNotFoundException;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\models\SearchDebug;
use Tahadudhiya\SearchKit\models\SearchExclusion;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Runs a search that records what it does, for someone asking why it returned what it did. It runs
 * the ordinary search: there is no second pipeline here, and nothing is simulated.
 */
class Debugger extends Component
{
    private ?Search $_search = null;

    /**
     * @throws InvalidQueryException|IndexNotFoundException|IndexDisabledException|ProviderException
     */
    public function run(SearchQuery $query): SearchResult
    {
        // Matched fields are worked out from the index's own values, so they are only available
        // when the search was asked to explain its matches.
        $query->highlight = true;
        $query->startDebug();

        $result = $this->getSearch()->search($query);

        if ($result->debug !== null) {
            $this->nameSearchExclusions($result->debug);
        }

        return $result;
    }

    /**
     * Names what SearchKit kept out, and says what state each of them is in. A rule's target was
     * never loaded by the search, so it is read here rather than left as an ID.
     */
    private function nameSearchExclusions(SearchDebug $debug): void
    {
        /** @var array<string,array<string,SearchExclusion[]>> $wanted */
        $wanted = [];

        foreach ($debug->exclusions as $excluded) {
            if ($excluded->elementType !== null && $excluded->title === null) {
                $wanted[$excluded->elementType][(string)($excluded->siteId ?? '*')][] = $excluded;
            }
        }

        foreach ($wanted as $elementType => $bySite) {
            foreach ($bySite as $siteId => $entries) {
                $this->name($elementType, $siteId, $entries);
            }
        }
    }

    /**
     * @param SearchExclusion[] $entries
     */
    private function name(string $elementType, string $siteId, array $entries): void
    {
        if (!is_subclass_of($elementType, ElementInterface::class)) {
            return;
        }

        try {
            // Every status, because the state a result is in is often the reason it is missing.
            $elements = $elementType::find()
                ->id(array_map(static fn(SearchExclusion $entry) => $entry->elementId, $entries))
                ->siteId($siteId === '*' ? '*' : (int)$siteId)
                ->status(null)
                ->indexBy('id')
                ->all();
        } catch (Throwable $e) {
            Craft::warning("Could not read an excluded element: {$e->getMessage()}", SearchKit::LOG_CATEGORY);

            return;
        }

        foreach ($entries as $entry) {
            $element = $elements[$entry->elementId] ?? null;

            if ($element !== null) {
                $entry->title = $element->getUiLabel();
                $entry->status = $element->getStatus();
            }
        }
    }

    public function setSearch(Search $search): void
    {
        $this->_search = $search;
    }

    public function getSearch(): Search
    {
        return $this->_search ??= SearchKit::getInstance()?->getSearch()
            ?? throw new InvalidConfigException('Search Kit is not installed or is disabled.');
    }
}
