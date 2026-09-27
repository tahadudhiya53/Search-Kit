<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\base\ElementInterface;
use Tahadudhiya\SearchKit\base\SearchProviderInterface;
use Tahadudhiya\SearchKit\enums\FilterOperator;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\errors\IndexDisabledException;
use Tahadudhiya\SearchKit\errors\IndexNotFoundException;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\errors\SearchKitException;
use Tahadudhiya\SearchKit\errors\UnauthorizedQueryException;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\events\SearchEvent;
use Tahadudhiya\SearchKit\models\ParsedQuery;
use Tahadudhiya\SearchKit\models\RulePlan;
use Tahadudhiya\SearchKit\models\SearchDebug;
use Tahadudhiya\SearchKit\models\SearchFilter;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;

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
    private ?QueryPipeline $_queryPipeline = null;
    private ?Suggestions $_suggestions = null;
    private ?RuleEngine $_ruleEngine = null;

    /**
     * @throws InvalidQueryException|IndexNotFoundException|IndexDisabledException|ProviderException
     */
    public function search(SearchQuery $query): SearchResult
    {
        if (!$query->validate()) {
            throw new InvalidQueryException('The search query is not valid.', $query->getErrors());
        }

        $debug = $query->getDebug();
        $index = $this->resolveIndex($query->indexHandle);
        $this->getSearchableFields()->attachFields($index);

        $this->assertQuerySiteIsUsable($query, $index);
        $this->assertStatusIsKnown($query, $index);
        $this->assertStatusIsViewable($query, $index);

        $provider = $this->getProviders()->getProviderForIndex($index);
        $this->assertProviderCanRun($query, $provider);

        $debug?->readIndex($index, $query->getSiteScope($index->siteId));
        $debug?->readProvider($provider);
        $debug?->mark('setup');

        // Everything the query text means is settled here, so a provider is only ever handed terms.
        $parsed = $this->getQueryPipeline()->parse($query, $index, $provider);
        $this->assertQueryHasTerms($parsed);
        $query->setParsedQuery($parsed);

        // Read before the search runs, so what was typed is kept whatever a correction goes on to
        // search for instead.
        $debug?->readOriginalQuery($parsed, $index->getSearchSettings()->operators);
        $debug?->mark('parse');

        // Settled before the search runs: the rules decide which results the provider must leave
        // out and how wide a window the page is read in, neither of which it can be told afterwards.
        $plan = $this->getRuleEngine()->plan($query, $index);
        $this->assertProviderCanExclude($plan, $provider);
        $execution = $this->getRuleEngine()->windowQuery($query, $plan);
        $debug?->mark('rules');

        $this->trigger(self::EVENT_BEFORE_SEARCH, new SearchEvent([
            'query' => $query,
            'index' => $index,
        ]));

        $startedAt = hrtime(true);
        $result = $this->execute($execution, $index, $provider, SearchDebug::PURPOSE_SEARCH);
        $result = $this->correctAndRetry($result, $execution, $index, $provider, $plan);

        // A correction happened to the query that ran, and everything downstream reads the terms
        // the results actually came from.
        $query->setParsedQuery($execution->getParsedQuery());

        if ($result->wasCorrected()) {
            [$plan, $execution, $result] = $this->replan($result, $query, $index, $provider, $execution);
        }

        // Normalizing these here is what makes every result comparable, whatever the provider did.
        $result->executionTime = (hrtime(true) - $startedAt) / 1_000_000;
        $result->indexHandle = $index->handle;
        $result->provider = $provider::class;
        $result->limit = $query->limit;
        $result->offset = $query->offset;
        $result->parsedQuery = $query->getParsedQuery();

        $debug?->readEffectiveQuery($query->getParsedQuery());
        $debug?->mark('provider');

        $this->normalizeHitSites($result, $query, $index);

        // Counted over the search rather than the page, and before the rules rearrange anything: a
        // facet describes every result the query matched.
        $result->facets = $this->countFacets($query, $index, $provider, $plan);
        $debug?->mark('facets');

        $this->getRuleEngine()->apply($result, $plan, $query, $execution, $index, $this->fetchAdjusted($plan, $query, $index, $provider));
        $debug?->mark('rules');
        $this->hydrateElements($result, $query);
        $this->assertResultsAreViewable($result, $query, $index);
        $debug?->mark('elements');

        // A result a rule placed is loaded and authorized like every other one, so one the viewer
        // may not see has already been dropped. This settles the explanation with it, rather than
        // reporting a placement that never happened and naming content nobody was shown.
        $this->getRuleEngine()->reconcilePlacements($plan);

        // A provider that highlights for itself keeps its own answer; this fills in for one that
        // cannot, so highlighting is part of the API whatever is serving the index.
        if ($query->highlight && !$provider->supports(ProviderCapability::Highlighting)) {
            $this->getHighlighting()->apply($result, $query, $index);
        }

        $debug?->mark('highlighting');
        $this->suggestAlternatives($result, $query, $index);
        $debug?->mark('suggestions');

        $debug?->explain($result, $plan, $index);
        $result->debug = $debug;

        $this->trigger(self::EVENT_AFTER_SEARCH, new SearchEvent([
            'query' => $query,
            'index' => $index,
            'result' => $result,
        ]));

        return $result;
    }

    /**
     * Completions for a query someone is still typing. This never runs a search: it reads the words
     * the index holds, so it stays cheap enough to call on every keystroke.
     *
     * @return string[]
     * @throws InvalidQueryException|IndexNotFoundException|IndexDisabledException
     */
    public function autocomplete(SearchQuery $query, ?int $limit = null): array
    {
        $index = $this->resolveIndex($query->indexHandle);
        $this->assertQuerySiteIsUsable($query, $index);

        return $this->getSuggestions()->autocomplete(
            $index,
            $query->text,
            $limit ?? $index->getSearchSettings()->suggestionLimit,
            $query->getSiteScope($index->siteId),
        );
    }

    /**
     * What else could be searched for instead of this query, whether or not it has been run.
     *
     * @return string[]
     * @throws InvalidQueryException|IndexNotFoundException|IndexDisabledException|ProviderException
     */
    public function suggest(SearchQuery $query, ?int $limit = null): array
    {
        $index = $this->resolveIndex($query->indexHandle);
        $this->assertQuerySiteIsUsable($query, $index);

        if ($query->getNormalizedText() === '') {
            return [];
        }

        $parsed = $this->getQueryPipeline()->parse($query, $index, $this->getProviders()->getProviderForIndex($index));

        if ($parsed->isEmpty()) {
            return [];
        }

        return $this->getSuggestions()->forQuery(
            $parsed,
            $index,
            $query->getSiteScope($index->siteId),
            $limit ?? $index->getSearchSettings()->suggestionLimit,
        );
    }

    /**
     * @throws ProviderException
     */
    private function execute(
        SearchQuery $query,
        SearchIndex $index,
        SearchProviderInterface $provider,
        string $purpose,
    ): SearchResult {
        $debug = $query->getDebug();
        $startedAt = hrtime(true);

        try {
            $result = $provider->search($query, $index);
            $debug?->recordExecution(
                $purpose,
                $query,
                $result,
                (hrtime(true) - $startedAt) / 1_000_000,
                // Only what this provider declares safe to show: its metadata is never shown raw.
                $provider->diagnostics($result->metadata),
            );

            return $result;
        } catch (SearchKitException $e) {
            throw $e;
        } catch (Throwable $e) {
            Craft::error("Search failed on “{$index->handle}”: {$e->getMessage()}", SearchKit::LOG_CATEGORY);
            throw new ProviderException("The “{$index->name}” search index could not be searched.", 0, $e);
        }
    }

    /**
     * A query that found nothing is tried again against the words the index actually holds. This
     * only ever runs when there was nothing to return, so a search that worked pays nothing for it.
     *
     * @throws ProviderException
     */
    private function correctAndRetry(
        SearchResult $result,
        SearchQuery $query,
        SearchIndex $index,
        SearchProviderInterface $provider,
        RulePlan $plan,
    ): SearchResult {
        // A provider that tolerates typos itself has already done this, and better. Results the
        // rules place are held back from the provider, so a search that places any has found
        // something and is not corrected out from under it.
        if ($result->total > 0 || $plan->placedCount() > 0 || $provider->supports(ProviderCapability::TypoTolerance)) {
            return $result;
        }

        $original = $query->getParsedQuery();
        $corrected = $this->getSuggestions()->correct($original, $index, $query->getSiteScope($index->siteId));

        if ($corrected === null) {
            return $result;
        }

        $query->setParsedQuery($corrected);
        $retried = $this->execute($query, $index, $provider, SearchDebug::PURPOSE_CORRECTION);

        if ($retried->total === 0) {
            // The correction was no better, so the search stands as it was asked.
            $query->setParsedQuery($original);
            return $result;
        }

        $retried->correctedText = $corrected->getText();

        return $retried;
    }

    /**
     * Rules govern the query that actually ran, so a correction has them read again against the
     * corrected text. The search is only run a second time when the new rules need results the first
     * window did not cover — a correction that changes nothing costs nothing.
     *
     * @return array{RulePlan,SearchQuery,SearchResult}
     * @throws ProviderException
     */
    private function replan(
        SearchResult $result,
        SearchQuery $query,
        SearchIndex $index,
        SearchProviderInterface $provider,
        SearchQuery $execution,
    ): array {
        $engine = $this->getRuleEngine();
        $plan = $engine->plan($query, $index, $result->correctedText);
        $this->assertProviderCanExclude($plan, $provider);

        $planned = $engine->windowQuery($query, $plan);

        if ($engine->windowCovers($plan, $execution, $planned)) {
            return [$plan, $execution, $result];
        }

        $corrected = $result->correctedText;
        $retried = $this->execute($planned, $index, $provider, SearchDebug::PURPOSE_REPLAN);
        $retried->correctedText = $corrected;

        return [$plan, $planned, $retried];
    }

    /**
     * The results a boost or a bury moves, asked for by name. They are held back from the ranked
     * list, so this is what puts them back — at the score the provider gave them, which is the only
     * honest thing to move. A result the search did not match is simply not returned.
     *
     * @return SearchHit[]
     * @throws ProviderException
     */
    private function fetchAdjusted(
        RulePlan $plan,
        SearchQuery $query,
        SearchIndex $index,
        SearchProviderInterface $provider,
    ): array {
        if (!$plan->refetchesAdjusted) {
            return [];
        }

        $wanted = [];
        $ids = [];

        foreach ($plan->adjustedElements() as $target) {
            $wanted[RulePlan::key($target['elementId'], $target['siteId'])] = true;
            $ids[$target['elementId']] = $target['elementId'];
        }

        // One element answers once per site, so a search covering several needs room for each of
        // them: asking for one row per result would leave the wanted site's copy outside the probe.
        $scope = $query->getSiteScope($index->siteId);
        $perElement = $scope !== null ? count($scope) : $this->scopeSiteCount();

        $hits = [];

        // Asked for in batches small enough that every site's copy fits inside one search, so no
        // result is ever left out by the limit a single search may carry.
        foreach (array_chunk(array_values($ids), max(1, intdiv(SearchQuery::MAX_LIMIT, $perElement))) as $batch) {
            foreach ($this->fetchBatch($batch, $perElement, $query, $index, $provider) as $hit) {
                // A result a rule already removed or placed is not also moved: it is accounted for
                // once, where that rule put it.
                if (!isset($wanted[RulePlan::key($hit->elementId, $hit->siteId)])
                    && !isset($wanted[RulePlan::key($hit->elementId, null)])) {
                    continue;
                }

                if ($plan->claimedBy($hit->elementId, $hit->siteId) !== null) {
                    continue;
                }

                $hits[RulePlan::key($hit->elementId, $hit->siteId)] = $hit;
            }
        }

        return array_values($hits);
    }

    /**
     * How many sites a search of an index covering all of them can answer for. Every one of them may
     * hold its own copy of a result, which is what decides how many fit in one search.
     */
    protected function scopeSiteCount(): int
    {
        return max(1, count(Craft::$app->getSites()->getAllSiteIds()));
    }

    /**
     * One batch of adjusted results, read with the same query so their scores are the ones this
     * search produced rather than something worked out separately.
     *
     * @param int[] $ids
     * @return SearchHit[]
     * @throws ProviderException
     */
    private function fetchBatch(
        array $ids,
        int $perElement,
        SearchQuery $query,
        SearchIndex $index,
        SearchProviderInterface $provider,
    ): array {
        $probe = clone $query;
        $probe->offset = 0;
        $probe->limit = min(count($ids) * $perElement, SearchQuery::MAX_LIMIT);
        $probe->setParsedQuery($query->getParsedQuery());
        $probe->addFilter(SearchFilter::make('id', FilterOperator::In, $ids));

        $found = $this->execute($probe, $index, $provider, SearchDebug::PURPOSE_ADJUSTED);
        $this->normalizeHitSites($found, $query, $index);

        return $found->hits;
    }

    /**
     * How the result set divides up by each field the search asked to be counted by. Only the
     * results a rule hides are left out: one a rule pins or promotes is still a result, so counting
     * it where the provider placed it is the only honest answer.
     *
     * @return \Tahadudhiya\SearchKit\models\Facet[]
     * @throws ProviderException
     */
    private function countFacets(
        SearchQuery $query,
        SearchIndex $index,
        SearchProviderInterface $provider,
        RulePlan $plan,
    ): array {
        if (!$query->hasFacets()) {
            return [];
        }

        $counted = clone $query;
        $counted->setParsedQuery($query->getParsedQuery());

        foreach ($plan->hidden as $target) {
            $counted->excludeElement($target['elementId'], $target['siteId']);
        }

        try {
            return $provider->facets($counted, $index);
        } catch (SearchKitException $e) {
            throw $e;
        } catch (Throwable $e) {
            Craft::error("Counting “{$index->handle}” failed: {$e->getMessage()}", SearchKit::LOG_CATEGORY);
            throw new ProviderException("The “{$index->name}” search index could not be counted.", 0, $e);
        }
    }

    /**
     * A rule that removes or places a result needs the provider to leave it out of the search
     * entirely; one that cannot is refused rather than hiding only what happened to be read.
     *
     * @throws UnsupportedCapabilityException
     */
    private function assertProviderCanExclude(RulePlan $plan, SearchProviderInterface $provider): void
    {
        if ($plan->excludedElements() !== [] && !$provider->supports(ProviderCapability::ResultExclusion)) {
            throw UnsupportedCapabilityException::for($provider::displayName(), ProviderCapability::ResultExclusion);
        }

        // Moving a result needs it asked for by name, which is a filter.
        if ($plan->refetchesAdjusted && !$provider->supports(ProviderCapability::Filtering)) {
            throw UnsupportedCapabilityException::for($provider::displayName(), ProviderCapability::Filtering);
        }
    }

    /**
     * Something else to try, for a search that came back with nothing.
     */
    private function suggestAlternatives(SearchResult $result, SearchQuery $query, SearchIndex $index): void
    {
        $settings = $index->getSearchSettings();

        if (!$result->isEmpty() || !$settings->suggestions) {
            return;
        }

        $result->suggestions = $this->getSuggestions()->forQuery(
            $query->getParsedQuery(),
            $index,
            $query->getSiteScope($index->siteId),
            $settings->suggestionLimit,
        );
    }

    /**
     * A query that only rules things out has nothing to look for, which no provider can answer.
     *
     * @throws InvalidQueryException
     */
    private function assertQueryHasTerms(ParsedQuery $parsed): void
    {
        if ($parsed->isEmpty()) {
            throw new InvalidQueryException(
                'The search has nothing to look for.',
                ['text' => ['A search needs at least one term that is not an exclusion.']],
            );
        }
    }

    /**
     * Every hit names the site it was found in, so nothing downstream has to guess which variant
     * of an element it holds. A provider that cannot say inherits the scope the search ran in.
     *
     * @throws ProviderException
     */
    private function normalizeHitSites(SearchResult $result, SearchQuery $query, SearchIndex $index): void
    {
        $scope = $query->getSiteScope($index->siteId);

        foreach ($result->hits as $hit) {
            // Only a search of one site can say which site a provider that did not answer meant.
            $hit->siteId ??= $scope !== null && count($scope) === 1 ? $scope[0] : null;

            if ($hit->siteId === null) {
                throw new ProviderException("A search of “{$index->handle}” returned a result without a site.");
            }

            // A provider may only answer within the scope it was given; it can never widen it.
            $outOfScope = $scope !== null
                ? !in_array($hit->siteId, $scope, true)
                : !$index->coversSite($hit->siteId);

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

        $this->dropHitsWithoutElements($result, $query);
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
    private function dropHitsWithoutElements(SearchResult $result, SearchQuery $query): void
    {
        $debug = $query->getDebug();

        if ($debug !== null) {
            foreach ($result->hits as $hit) {
                if ($hit->element === null) {
                    $debug->recordDropped($hit);
                }
            }
        }

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
        $requested = $query->getSiteIds() ?? array_filter([$query->siteId]);

        foreach ($requested as $siteId) {
            // Disabled sites still count: Craft can search them, so SearchKit does not add a stricter rule.
            if (Craft::$app->getSites()->getSiteById($siteId, true) === null) {
                throw new InvalidQueryException(
                    'The requested site does not exist.',
                    ['siteId' => ["No site exists with the ID {$siteId}."]],
                );
            }

            if (!$index->coversSite($siteId)) {
                throw new InvalidQueryException(
                    "The “{$index->name}” search index does not cover the requested site.",
                    ['siteId' => ["Site {$siteId} is outside this index's scope."]],
                );
            }
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

        if ($query->hasFacets() && !$provider->supports(ProviderCapability::Faceting)) {
            throw UnsupportedCapabilityException::for($name, ProviderCapability::Faceting);
        }
    }

    public function setIndexes(Indexes $indexes): void
    {
        $this->_indexes = $indexes;
    }

    public function getIndexes(): Indexes
    {
        return $this->_indexes ??= SearchKit::instance()->getIndexes();
    }

    public function setSearchableFields(SearchableFields $searchableFields): void
    {
        $this->_searchableFields = $searchableFields;
    }

    public function getSearchableFields(): SearchableFields
    {
        return $this->_searchableFields ??= SearchKit::instance()->getSearchableFields();
    }

    public function setHighlighting(Highlighting $highlighting): void
    {
        $this->_highlighting = $highlighting;
    }

    public function getHighlighting(): Highlighting
    {
        return $this->_highlighting ??= SearchKit::instance()->getHighlighting();
    }

    public function setQueryPipeline(QueryPipeline $queryPipeline): void
    {
        $this->_queryPipeline = $queryPipeline;
    }

    public function getQueryPipeline(): QueryPipeline
    {
        return $this->_queryPipeline ??= SearchKit::instance()->getQueryPipeline();
    }

    public function setSuggestions(Suggestions $suggestions): void
    {
        $this->_suggestions = $suggestions;
    }

    public function getSuggestions(): Suggestions
    {
        return $this->_suggestions ??= SearchKit::instance()->getSuggestions();
    }

    public function setRuleEngine(RuleEngine $ruleEngine): void
    {
        $this->_ruleEngine = $ruleEngine;
    }

    public function getRuleEngine(): RuleEngine
    {
        return $this->_ruleEngine ??= SearchKit::instance()->getRuleEngine();
    }

    public function setProviders(Providers $providers): void
    {
        $this->_providers = $providers;
    }

    public function getProviders(): Providers
    {
        return $this->_providers ??= SearchKit::instance()->getProviders();
    }
}
