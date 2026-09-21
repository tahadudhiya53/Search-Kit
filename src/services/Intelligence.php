<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use DateTime;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\enums\AnomalyType;
use Tahadudhiya\SearchKit\models\Anomaly;
use Tahadudhiya\SearchKit\models\ClickedResult;
use Tahadudhiya\SearchKit\models\InsightsCriteria;
use Tahadudhiya\SearchKit\models\InsightsSummary;
use Tahadudhiya\SearchKit\models\QualityScore;
use Tahadudhiya\SearchKit\models\QueryInsight;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SynonymCandidate;
use Tahadudhiya\SearchKit\SearchKit;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\caching\TagDependency;
use yii\db\Expression;

/**
 * What the recorded searches mean, as opposed to what they add up to. Everything here is read from
 * activity that was already recorded — nothing is learned, nothing is predicted, and nothing
 * changes a search on its own.
 */
class Intelligence extends Component
{
    /** @var int Days each window of a comparison covers, unless a different length is asked for. */
    public const WINDOW_DAYS = 7;

    /**
     * @var int Searches a window needs before it is compared at all. This is the whole of the
     * defence against noisy alerts: a rate over a handful of searches is not a measurement.
     */
    public const MIN_WINDOW_SEARCHES = 20;

    /** @var float Rate points the share of searches finding nothing must rise by. */
    public const ZERO_RESULT_RISE = 0.15;

    /** @var float The share it must also reach, so a rise from nearly nothing is not reported. */
    public const ZERO_RESULT_FLOOR = 0.25;

    /** @var float How much of the baseline search volume must change, either way. */
    public const VOLUME_CHANGE = 0.5;

    /** @var float How many times the baseline a response time must reach. */
    public const RESPONSE_TIME_MULTIPLE = 1.5;

    /** @var int Results two queries must have in common before they look like the same thing. */
    public const MIN_SHARED_RESULTS = 2;

    /** @var float How much of the narrower query's opened results must be shared. */
    public const MIN_OVERLAP = 0.5;

    /** @var int Queries a discovery run weighs against each other, most searched first. */
    public const MAX_DISCOVERY_QUERIES = 200;

    /** @var int Query-and-result rows a discovery run reads, most opened first. */
    public const MAX_CLICK_ROWS = 2000;

    /** @var float The share of searches finding nothing that makes a popular query a gap. */
    public const GAP_ZERO_RESULT_RATE = 0.5;

    /** @var float The average position past which people are scrolling to find what they wanted. */
    public const DEEP_POSITION = 5.0;

    /** @var int Clicks a result needs before its average position means anything. */
    public const MIN_DEEP_CLICKS = 3;

    private ?Insights $_insights = null;
    private ?Indexes $_indexes = null;
    private ?Synonyms $_synonyms = null;
    private ?Intent $_intent = null;
    private ?Normalization $_normalization = null;

    /**
     * How well search served people over the period. See `QualityScore` for the calculation.
     *
     * Which indexes follow opened results is settled here rather than by the caller, because
     * engagement needs its own denominator: over a reading covering an index that follows clicks
     * and one that does not, the second index's searches must not count as searches nobody opened
     * anything from. They were never watched.
     */
    public function getQualityScore(InsightsCriteria $criteria): QualityScore
    {
        $criteria = $this->judgedByOwnIndex($criteria);
        $tracking = $this->indexesFollowingClicks($criteria);

        if ($tracking === []) {
            return QualityScore::from($this->getInsights()->getSummary($criteria));
        }

        $summary = $this->getInsights()->getSummary($criteria);

        // Every index in the reading follows clicks, so engagement is the whole period already and
        // there is nothing to read a second time.
        if ($this->indexesRead($criteria) === $tracking) {
            return QualityScore::from($summary, $summary);
        }

        $tracked = clone $criteria;
        $tracked->indexIds = $tracking;

        return QualityScore::from($summary, $this->getInsights()->getSummary($tracked));
    }

    /**
     * The same reading, with every index's own idea of slow attached. Without this an aggregate
     * would judge every search by one index's threshold, which is nobody's.
     */
    public function judgedByOwnIndex(InsightsCriteria $criteria): InsightsCriteria
    {
        if ($criteria->slowThresholds !== []) {
            return $criteria;
        }

        $criteria = clone $criteria;

        // Only the indexes this reading covers, so filtering to one index is judged by that one.
        foreach ($this->getIndexes()->getAllIndexes() as $index) {
            if ($index->id !== null && $this->covers($criteria, (int)$index->id)) {
                $criteria->slowThresholds[(int)$index->id] = $index->getAnalyticsSettings()->slowThreshold;
            }
        }

        return $criteria;
    }

    /**
     * The indexes a reading covers, as IDs.
     *
     * @return int[]
     */
    private function indexesRead(InsightsCriteria $criteria): array
    {
        $ids = [];

        foreach ($this->getIndexes()->getAllIndexes() as $index) {
            if ($index->id !== null && $this->covers($criteria, (int)$index->id)) {
                $ids[] = (int)$index->id;
            }
        }

        return $ids;
    }

    /**
     * Of those, the ones that follow opened results.
     *
     * @return int[]
     */
    private function indexesFollowingClicks(InsightsCriteria $criteria): array
    {
        $ids = [];

        foreach ($this->getIndexes()->getAllIndexes() as $index) {
            if (
                $index->id !== null
                && $this->covers($criteria, (int)$index->id)
                && $index->getAnalyticsSettings()->trackClicks
            ) {
                $ids[] = (int)$index->id;
            }
        }

        return $ids;
    }

    private function covers(InsightsCriteria $criteria, int $indexId): bool
    {
        if ($criteria->indexId !== null && $criteria->indexId !== $indexId) {
            return false;
        }

        return $criteria->indexIds === null
            || in_array($indexId, array_map('intval', $criteria->indexIds), true);
    }

    /**
     * What changed between the most recent window and the one before it. Both windows end where the
     * period asked for ends, so filtering to a date range compares the run-up to that date.
     *
     * @return Anomaly[]
     */
    public function detectAnomalies(InsightsCriteria $criteria, int $windowDays = self::WINDOW_DAYS): array
    {
        $windowDays = max(1, $windowDays);
        $criteria = $this->judgedByOwnIndex($criteria);
        $end = $criteria->dateTo ?? new DateTime();

        $recent = $this->window($criteria, (clone $end)->modify("-{$windowDays} days"), $end);
        $baseline = $this->window(
            $criteria,
            (clone $end)->modify('-' . ($windowDays * 2) . ' days'),
            (clone $end)->modify("-{$windowDays} days"),
        );

        return $this->compare(
            $this->getInsights()->getSummary($recent),
            $this->getInsights()->getSummary($baseline),
            // The strictest bar among the indexes being read, so a response time no index of them
            // would call slow is never reported as one.
            max([$criteria->slowThreshold, ...array_values($criteria->slowThresholds)]),
            $windowDays,
        );
    }

    /**
     * The comparison itself, over two periods that have already been read. Separate from the
     * reading so the thresholds can be exercised without a database behind them.
     *
     * @return Anomaly[]
     */
    public function compare(
        InsightsSummary $recent,
        InsightsSummary $baseline,
        int $slowThreshold,
        int $windowDays = self::WINDOW_DAYS,
    ): array {
        // Without a baseline worth the name there is nothing to compare against, whatever happened.
        if ($baseline->totalSearches < self::MIN_WINDOW_SEARCHES) {
            return [];
        }

        $anomalies = [];
        $shape = [
            'recentSearches' => $recent->totalSearches,
            'baselineSearches' => $baseline->totalSearches,
            'windowDays' => $windowDays,
        ];

        // Volume is the one measurement that still means something when the recent window is empty:
        // search stopping altogether is exactly what this is here to catch.
        $change = $recent->totalSearches - $baseline->totalSearches;
        $relative = abs($change) / $baseline->totalSearches;

        if ($relative >= self::VOLUME_CHANGE && abs($change) >= self::MIN_WINDOW_SEARCHES) {
            $anomalies[] = new Anomaly([
                'type' => AnomalyType::Volume,
                'increased' => $change > 0,
                'recent' => (float)$recent->totalSearches,
                'baseline' => (float)$baseline->totalSearches,
            ] + $shape);
        }

        // A rate over a handful of searches is not a rate, so the rest need both windows to be real.
        if ($recent->totalSearches < self::MIN_WINDOW_SEARCHES) {
            return $anomalies;
        }

        $zeroResults = $recent->getZeroResultRate();

        if (
            $zeroResults >= self::ZERO_RESULT_FLOOR
            && $zeroResults - $baseline->getZeroResultRate() >= self::ZERO_RESULT_RISE
        ) {
            $anomalies[] = new Anomaly([
                'type' => AnomalyType::ZeroResults,
                'recent' => $zeroResults,
                'baseline' => $baseline->getZeroResultRate(),
            ] + $shape);
        }

        if (
            $baseline->averageResponseTime > 0
            && $recent->averageResponseTime >= $baseline->averageResponseTime * self::RESPONSE_TIME_MULTIPLE
            && $recent->averageResponseTime >= $slowThreshold
        ) {
            $anomalies[] = new Anomaly([
                'type' => AnomalyType::ResponseTime,
                'recent' => $recent->averageResponseTime,
                'baseline' => $baseline->averageResponseTime,
            ] + $shape);
        }

        return $anomalies;
    }

    /**
     * Demand the content does not answer: queries people search for often that come back with
     * nothing, or with nothing anybody opened. Looked for among the most-searched queries, since
     * that is where closing a gap is worth the work.
     *
     * @return QueryInsight[]
     */
    public function getContentGaps(InsightsCriteria $criteria): array
    {
        $gaps = [];

        foreach ($this->getInsights()->getPopularQueries($criteria) as $insight) {
            if ($insight->searches < max(1, $criteria->minSearches)) {
                continue;
            }

            if ($insight->getZeroResultRate() >= self::GAP_ZERO_RESULT_RATE || $insight->clicks === 0) {
                $gaps[] = $insight;
            }
        }

        return $gaps;
    }

    /**
     * Pairs of queries people open the same results from, which is what a synonym looks like from
     * the outside. Nothing here is applied: a pair is evidence for somebody to decide on.
     *
     * **Pairs are built within one site scope.** An index covering several sites is searched in
     * several languages, and two queries are only evidence about each other if the same people
     * searching the same content could have typed either one. So the site each search was recorded
     * for is carried through and two queries are only weighed against each other when they were
     * searched under the same scope. Result identity stays element *and* site, so two sites'
     * versions of one page remain two results.
     *
     * @param SearchIndex|null $index The index the pairs would be written for. Given one, pairs an
     *                                existing synonym group already covers are left out.
     * @return SynonymCandidate[]
     */
    public function discoverSynonyms(InsightsCriteria $criteria, ?SearchIndex $index = null): array
    {
        /** @var array<int,array<string,mixed>> $rows */
        $rows = $this->getInsights()->cached(
            $criteria->cacheKey('discovery'),
            fn() => $this->getInsights()->events($criteria, 'e')
                ->innerJoin(['c' => Table::SEARCHCLICKS], '[[c.eventId]] = [[e.id]]')
                ->select([
                    'normalizedQuery' => 'e.normalizedQuery',
                    'eventSiteId' => 'e.siteId',
                    'elementId' => 'c.elementId',
                    'clickSiteId' => 'c.siteId',
                ])
                ->groupBy(['e.normalizedQuery', 'e.siteId', 'c.elementId', 'c.siteId'])
                ->orderBy([new Expression('COUNT(*) DESC'), 'e.normalizedQuery' => SORT_ASC])
                ->limit(self::MAX_CLICK_ROWS)
                ->all(),
        );

        $candidates = $this->pair($rows, $this->searchCounts($criteria));

        if ($index !== null) {
            $candidates = array_values(array_filter(
                $candidates,
                // Asked about the candidate's own site, not the reading's: that is the scope a group
                // written from it would belong to.
                fn(SynonymCandidate $candidate) => !$this->alreadyCovered($candidate, $index),
            ));
        }

        return array_slice($candidates, 0, $criteria->limit);
    }

    /**
     * How a query and the scope it was searched under are held together, so two queries from
     * different scopes are never the same thing to weigh.
     */
    public static function bucket(?int $siteId, string $query): string
    {
        return ($siteId ?? '*') . '|' . $query;
    }

    /**
     * The pairing itself, over rows that have already been read. Separate from the reading so the
     * thresholds can be exercised without a database behind them.
     *
     * @param array<int,array<string,mixed>> $rows One row per query, search site and opened result.
     * @param array<string,int> $searchCounts `bucket()` => how often that query was searched for
     *                                        under that scope.
     * @return SynonymCandidate[]
     */
    public function pair(array $rows, array $searchCounts): array
    {
        $opened = [];

        foreach ($rows as $row) {
            $query = (string)$row['normalizedQuery'];
            // Absent or null both mean a search that covered the index's whole scope.
            $site = isset($row['eventSiteId']) ? (int)$row['eventSiteId'] : null;
            $bucket = self::bucket($site, $query);

            // Only queries searched for often enough to mean something are weighed against others.
            if (!isset($searchCounts[$bucket])) {
                continue;
            }

            $opened[$bucket]['query'] = $query;
            $opened[$bucket]['site'] = $site;
            $opened[$bucket]['results'][(int)$row['elementId'] . ':' . (int)$row['clickSiteId']] = true;
        }

        // The most searched first, so a cap drops the pairs that mattered least.
        uksort($opened, static fn(string $a, string $b) => [$searchCounts[$b], $a] <=> [$searchCounts[$a], $b]);
        $buckets = array_slice(array_keys($opened), 0, self::MAX_DISCOVERY_QUERIES);
        $candidates = [];

        foreach ($buckets as $a) {
            foreach ($buckets as $b) {
                // One ordering of each pair, never a query against itself, and never across scopes:
                // what people did in one site is no evidence about the wording used in another.
                if ($opened[$a]['site'] !== $opened[$b]['site'] || strcmp($opened[$a]['query'], $opened[$b]['query']) >= 0) {
                    continue;
                }

                $shared = count(array_intersect_key($opened[$a]['results'], $opened[$b]['results']));

                if ($shared < self::MIN_SHARED_RESULTS) {
                    continue;
                }

                $candidate = new SynonymCandidate([
                    'first' => $opened[$a]['query'],
                    'second' => $opened[$b]['query'],
                    'siteId' => $opened[$a]['site'],
                    'firstSearches' => $searchCounts[$a],
                    'secondSearches' => $searchCounts[$b],
                    'firstResults' => count($opened[$a]['results']),
                    'secondResults' => count($opened[$b]['results']),
                    'sharedResults' => $shared,
                ]);

                if ($candidate->getOverlap() >= self::MIN_OVERLAP) {
                    $candidates[] = $candidate;
                }
            }
        }

        usort($candidates, static fn(SynonymCandidate $a, SynonymCandidate $b) => [$b->getOverlap(), $b->getSearches(), $a->first, $a->siteId ?? 0]
            <=> [$a->getOverlap(), $a->getSearches(), $b->first, $b->siteId ?? 0]);

        return $candidates;
    }

    /**
     * Results people open only after scrolling past several others, which is a result that belongs
     * higher than the provider puts it.
     *
     * @return ClickedResult[]
     */
    public function getDeepClicks(InsightsCriteria $criteria): array
    {
        /** @var array<int,array<string,mixed>> $rows */
        $rows = $this->getInsights()->cached(
            $criteria->cacheKey('deepClicks'),
            fn() => $this->getInsights()->events($criteria, 'e')
                ->innerJoin(['c' => Table::SEARCHCLICKS], '[[c.eventId]] = [[e.id]]')
                ->select([
                    'normalizedQuery' => 'e.normalizedQuery',
                    'elementId' => 'c.elementId',
                    'elementType' => 'c.elementType',
                    'clickSiteId' => 'c.siteId',
                    'clicks' => 'COUNT(*)',
                    'averagePosition' => 'AVG([[c.position]])',
                ])
                ->groupBy(['e.normalizedQuery', 'c.elementId', 'c.elementType', 'c.siteId'])
                ->having(['and',
                    ['>=', 'COUNT(*)', self::MIN_DEEP_CLICKS],
                    ['>=', 'AVG([[c.position]])', self::DEEP_POSITION],
                ])
                ->orderBy([new Expression('COUNT(*) DESC'), 'e.normalizedQuery' => SORT_ASC])
                ->limit($criteria->limit)
                ->all(),
        );

        $results = array_map(static fn(array $row) => new ClickedResult([
            'query' => (string)$row['normalizedQuery'],
            'elementId' => (int)$row['elementId'],
            'elementType' => (string)$row['elementType'],
            'siteId' => (int)$row['clickSiteId'],
            'clicks' => (int)$row['clicks'],
            'averagePosition' => round((float)$row['averagePosition'], 2),
        ]), $rows);

        // Named where the reading is, so a recommendation about a result can say which result.
        $this->getInsights()->nameResults($results);

        return $results;
    }

    /**
     * How the most-searched queries read, by how many searches each reading accounts for. The
     * reading is a dictionary of cue words, so this describes the wording people use and should
     * never be presented as anything more.
     *
     * @return array<string,int> Intent => searches whose wording read that way.
     */
    public function getIntentBreakdown(InsightsCriteria $criteria): array
    {
        $language = $this->getNormalization()->siteLanguage($criteria->siteId);
        $breakdown = [];

        foreach ($this->getInsights()->getPopularQueries($criteria) as $insight) {
            $intent = $this->getIntent()->classify($insight->query, $language)->intent->value;
            $breakdown[$intent] = ($breakdown[$intent] ?? 0) + $insight->searches;
        }

        arsort($breakdown);

        return $breakdown;
    }

    /**
     * Queries people have searched for, found something with, and opened something from — the only
     * ones an index may offer back to somebody else. Answering with what somebody typed is a
     * privacy decision, which is why it is off unless asked for and why a query has to have been
     * searched for repeatedly, across separate days, before it is offered at all.
     *
     * The words themselves are checked against published content by whoever offers them, so a
     * query naming something nobody may find is never shown.
     *
     * **Scoped to the sites being suggested for**, and only ever narrowed by it. A search recorded
     * for one site is offered in that site; a search covering the index's whole scope is offered
     * only when the whole scope is what is being suggested for, since it is not attributable to any
     * one of those sites — the same rule a synonym group written for one site is held to. Without
     * that, a query from one site could be offered in another simply because its words happen to be
     * visible there, which would cross a site and language boundary the search itself never crossed.
     *
     * @param string $prefix What has been typed so far, normalized in the language of these sites.
     *                       Empty offers the most searched.
     * @param int[]|null $siteId The sites being suggested for, or null for every site the index
     *                           covers.
     * @return string[] Most searched first.
     */
    public function getSuggestableQueries(SearchIndex $index, string $prefix, int $limit, ?array $siteId = null): array
    {
        $settings = $index->getAnalyticsSettings();

        if ($index->id === null || $limit < 1 || !$settings->enabled || !$settings->suggestPopularQueries) {
            return [];
        }

        $scope = $siteId !== null ? array_values(array_unique(array_map('intval', $siteId))) : null;

        // An empty list is the empty scope, not every site, so it can offer nothing.
        if ($scope === []) {
            return [];
        }

        $criteria = new InsightsCriteria(['indexId' => (int)$index->id, 'limit' => $limit]);
        $key = $criteria->cacheKey('suggestable:' . $prefix . ':' . $settings->suggestionMinSearches
            . ':' . $settings->suggestionMinDays . ':' . $this->scopeKey($scope));

        /** @var string[] $queries */
        $queries = $this->remembered($key, function() use ($criteria, $prefix, $limit, $settings, $scope) {
            $day = 'CAST([[dateCreated]] AS date)';
            $query = $this->getInsights()->events($criteria)
                ->select(['normalizedQuery'])
                ->andWhere(['>', 'resultCount', 0])
                ->groupBy(['normalizedQuery'])
                ->having(['and',
                    ['>=', 'COUNT(*)', $settings->suggestionMinSearches],
                    ['>=', new Expression('COUNT(DISTINCT ' . $day . ')'), $settings->suggestionMinDays],
                    // Something was opened, so the query is known to lead somewhere real.
                    ['>', 'COALESCE(SUM([[clickCount]]), 0)', 0],
                ])
                ->orderBy([new Expression('COUNT(*) DESC'), 'normalizedQuery' => SORT_ASC])
                ->limit($limit);

            // Searches of these sites alone. A search covering the index's whole scope recorded no
            // site and is not one of them, exactly as every other reading of activity treats it.
            if ($scope !== null) {
                $query->andWhere(['siteId' => $scope]);
            }

            if ($prefix !== '') {
                // Escaped and matched as a prefix: nothing typed may become a pattern of its own.
                $query->andWhere(['like', 'normalizedQuery', addcslashes($prefix, '\\%_') . '%', false]);
            }

            return array_map('strval', $query->column());
        });

        return $queries;
    }

    /**
     * @param int[]|null $scope
     */
    private function scopeKey(?array $scope): string
    {
        if ($scope === null) {
            return '*';
        }

        $sites = $scope;
        sort($sites);

        return implode('-', $sites);
    }

    /**
     * A plain expiring cache, deliberately not the one every other reading here uses. This one is
     * read while somebody types, and keying it on the last recorded search would throw it away on
     * every search the site runs — turning a keystroke back into a grouped query. A suggestion
     * being a few minutes behind costs nothing; two readings of it cannot disagree about anything.
     *
     * @template T
     * @param callable():T $read
     * @return T
     */
    private function remembered(string $key, callable $read): mixed
    {
        $cache = Craft::$app->getCache();
        $value = $cache->get($key);

        if ($value === false) {
            $value = ['value' => $read()];
            $cache->set($key, $value, Insights::CACHE_DURATION, new TagDependency(['tags' => Analytics::CACHE_TAG]));
        }

        return $value['value'];
    }

    /**
     * Whether a synonym group already treats these two as the same thing, in which case there is
     * nothing to recommend.
     */
    private function alreadyCovered(SynonymCandidate $candidate, SearchIndex $index): bool
    {
        $siteId = $candidate->siteId;

        return in_array($candidate->second, $this->getSynonyms()->expand($candidate->first, $index, $siteId), true)
            || in_array($candidate->first, $this->getSynonyms()->expand($candidate->second, $index, $siteId), true);
    }

    /**
     * How often each query worth weighing was searched for, counted per site scope — because that
     * is the unit a pair is built in.
     *
     * @return array<string,int>
     */
    private function searchCounts(InsightsCriteria $criteria): array
    {
        /** @var array<int,array<string,mixed>> $rows */
        $rows = $this->getInsights()->cached(
            $criteria->cacheKey('searchCounts'),
            fn() => $this->getInsights()->events($criteria)
                ->select(['normalizedQuery', 'siteId', 'searches' => 'COUNT(*)'])
                ->groupBy(['normalizedQuery', 'siteId'])
                ->having(['>=', 'COUNT(*)', max(1, $criteria->minSearches)])
                ->orderBy([new Expression('COUNT(*) DESC'), 'normalizedQuery' => SORT_ASC])
                ->limit(self::MAX_DISCOVERY_QUERIES)
                ->all(),
        );

        $counts = [];

        foreach ($rows as $row) {
            $site = $row['siteId'] !== null ? (int)$row['siteId'] : null;
            $counts[self::bucket($site, (string)$row['normalizedQuery'])] = (int)$row['searches'];
        }

        return $counts;
    }

    /**
     * The same reading over a different stretch of time, keeping everything else about it.
     */
    private function window(InsightsCriteria $criteria, DateTime $from, DateTime $to): InsightsCriteria
    {
        $window = clone $criteria;
        $window->dateFrom = $from;
        $window->dateTo = $to;

        return $window;
    }

    public function setInsights(Insights $insights): void
    {
        $this->_insights = $insights;
    }

    public function getInsights(): Insights
    {
        return $this->_insights ??= $this->plugin()->getInsights();
    }

    public function setIndexes(Indexes $indexes): void
    {
        $this->_indexes = $indexes;
    }

    public function getIndexes(): Indexes
    {
        return $this->_indexes ??= $this->plugin()->getIndexes();
    }

    public function setSynonyms(Synonyms $synonyms): void
    {
        $this->_synonyms = $synonyms;
    }

    public function getSynonyms(): Synonyms
    {
        return $this->_synonyms ??= $this->plugin()->getSynonyms();
    }

    public function setIntent(Intent $intent): void
    {
        $this->_intent = $intent;
    }

    public function getIntent(): Intent
    {
        return $this->_intent ??= $this->plugin()->getIntent();
    }

    public function setNormalization(Normalization $normalization): void
    {
        $this->_normalization = $normalization;
    }

    public function getNormalization(): Normalization
    {
        return $this->_normalization ??= $this->plugin()->getNormalization();
    }

    private function plugin(): SearchKit
    {
        return SearchKit::getInstance()
            ?? throw new InvalidConfigException('SearchKit is not installed or is disabled.');
    }
}
