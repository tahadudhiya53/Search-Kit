<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\models\AnalyticsEvent;
use Tahadudhiya\SearchKit\models\ClickedResult;
use Tahadudhiya\SearchKit\models\InsightsCriteria;
use Tahadudhiya\SearchKit\models\InsightsSummary;
use Tahadudhiya\SearchKit\models\QueryInsight;
use Tahadudhiya\SearchKit\models\TrendPoint;
use yii\base\Component;
use yii\caching\TagDependency;
use yii\db\Expression;

/**
 * What the recorded searches add up to. Every reading is one grouped query against an indexed date
 * range and is cached briefly, so opening a page never costs a scan of the whole table.
 */
class Insights extends Component
{
    /** @var int Seconds a reading is reused for, once nothing new has been recorded. */
    public const CACHE_DURATION = 300;


    /**
     * The totals for a period, in one pass over the range.
     */
    public function getSummary(InsightsCriteria $criteria): InsightsSummary
    {
        /** @var array<string,mixed> $row */
        $row = $this->cached($criteria->cacheKey('summary'), fn() => $this->events($criteria)
            ->select([
                'totalSearches' => 'COUNT(*)',
                'uniqueQueries' => 'COUNT(DISTINCT [[normalizedQuery]])',
                'zeroResultSearches' => $this->countWhere('[[resultCount]] = 0'),
                'clicks' => 'COALESCE(SUM([[clickCount]]), 0)',
                'clickedSearches' => $this->countWhere('[[clickCount]] > 0'),
                'averageResponseTime' => 'AVG([[executionTime]])',
                'slowSearches' => $this->countWhere('[[executionTime]] >= ' . (int)$criteria->slowThreshold),
            ])
            ->one() ?: []);

        return new InsightsSummary([
            'totalSearches' => (int)($row['totalSearches'] ?? 0),
            'uniqueQueries' => (int)($row['uniqueQueries'] ?? 0),
            'zeroResultSearches' => (int)($row['zeroResultSearches'] ?? 0),
            'clicks' => (int)($row['clicks'] ?? 0),
            'clickedSearches' => (int)($row['clickedSearches'] ?? 0),
            'averageResponseTime' => round((float)($row['averageResponseTime'] ?? 0), 3),
            'slowSearches' => (int)($row['slowSearches'] ?? 0),
        ]);
    }

    /**
     * What people searched for most.
     *
     * @return QueryInsight[]
     */
    public function getPopularQueries(InsightsCriteria $criteria): array
    {
        return $this->queries($criteria, 'popular');
    }

    /**
     * The queries that came back with nothing, most often first.
     *
     * @return QueryInsight[]
     */
    public function getZeroResultQueries(InsightsCriteria $criteria): array
    {
        return $this->queries($criteria, 'zeroResults', ['resultCount' => 0]);
    }

    /**
     * Demand nothing answered: queries searched for repeatedly where no result was ever opened,
     * whether because nothing was found or because nothing found was worth opening.
     *
     * @return QueryInsight[]
     */
    public function getContentGaps(InsightsCriteria $criteria): array
    {
        return $this->queries($criteria, 'gaps', null, static function(Query $query) use ($criteria) {
            $query->having(['and',
                ['>=', 'COUNT(*)', max(1, $criteria->minSearches)],
                ['=', 'COALESCE(SUM([[clickCount]]), 0)', 0],
            ]);
        });
    }

    /**
     * The queries that took longest, which is where a slow search is worth looking for.
     *
     * @return QueryInsight[]
     */
    public function getSlowQueries(InsightsCriteria $criteria): array
    {
        return $this->queries($criteria, 'slow', null, function(Query $query) use ($criteria) {
            $query
                ->having(['>=', 'AVG([[executionTime]])', $criteria->slowThreshold])
                ->orderBy([new Expression('AVG([[executionTime]]) DESC')]);
        });
    }

    /**
     * Search activity day by day, counted in the dates as they are stored — UTC.
     *
     * @return TrendPoint[]
     */
    public function getTrend(InsightsCriteria $criteria, ?string $query = null): array
    {
        $key = $criteria->cacheKey('trend:' . ($query ?? '*'));

        /** @var array<int,array<string,mixed>> $rows */
        $rows = $this->cached($key, function() use ($criteria, $query) {
            $day = 'CAST([[dateCreated]] AS date)';
            $builder = $this->events($criteria)
                ->select([
                    'day' => $day,
                    'searches' => 'COUNT(*)',
                    'zeroResults' => $this->countWhere('[[resultCount]] = 0'),
                    'clicks' => 'COALESCE(SUM([[clickCount]]), 0)',
                    'averageTime' => 'AVG([[executionTime]])',
                ])
                ->groupBy([new Expression($day)])
                ->orderBy([new Expression($day . ' ASC')]);

            if ($query !== null) {
                $builder->andWhere(['normalizedQuery' => $query]);
            }

            return $builder->all();
        });

        return array_map(static function(array $row) {
            $day = substr((string)$row['day'], 0, 10);

            return new TrendPoint([
                'date' => $day,
                'dateEnd' => $day,
                'searches' => (int)$row['searches'],
                'zeroResults' => (int)$row['zeroResults'],
                'clicks' => (int)$row['clicks'],
                'averageTime' => round((float)$row['averageTime'], 3),
            ]);
        }, $rows);
    }

    /**
     * Fewer points than there are days, for a period too long to read a day at a time. Days are
     * grouped in order and nothing is dropped, so the totals still describe the whole period.
     *
     * @param TrendPoint[] $points
     * @return TrendPoint[]
     */
    public function condenseTrend(array $points, int $maxPoints): array
    {
        $points = array_values($points);

        if ($maxPoints < 1 || count($points) <= $maxPoints) {
            return $points;
        }

        $condensed = [];

        foreach (array_chunk($points, (int)ceil(count($points) / $maxPoints)) as $chunk) {
            $searches = array_sum(array_map(static fn(TrendPoint $point) => $point->searches, $chunk));
            $time = array_sum(array_map(static fn(TrendPoint $point) => $point->averageTime * $point->searches, $chunk));

            $condensed[] = new TrendPoint([
                'date' => $chunk[0]->date,
                'dateEnd' => $chunk[count($chunk) - 1]->dateEnd,
                'searches' => $searches,
                'zeroResults' => array_sum(array_map(static fn(TrendPoint $point) => $point->zeroResults, $chunk)),
                'clicks' => array_sum(array_map(static fn(TrendPoint $point) => $point->clicks, $chunk)),
                // Weighted by how much was searched, so a quiet day cannot outweigh a busy one.
                'averageTime' => $searches > 0 ? round($time / $searches, 3) : 0.0,
            ]);
        }

        return $condensed;
    }

    /**
     * The results people opened most often. Read from what the clicks recorded — nothing is
     * searched for again to work this out.
     *
     * @return ClickedResult[]
     */
    public function getClickedResults(InsightsCriteria $criteria): array
    {
        /** @var array<int,array<string,mixed>> $rows */
        $rows = $this->cached($criteria->cacheKey('clicked'), fn() => $this->events($criteria, 'e')
            ->innerJoin(['c' => Table::SEARCHCLICKS], '[[c.eventId]] = [[e.id]]')
            ->select([
                'elementId' => 'c.elementId',
                'elementType' => 'c.elementType',
                'clickSiteId' => 'c.siteId',
                'clicks' => 'COUNT(*)',
                'averagePosition' => 'AVG([[c.position]])',
            ])
            ->groupBy(['c.elementId', 'c.elementType', 'c.siteId'])
            ->orderBy([new Expression('COUNT(*) DESC'), 'c.elementId' => SORT_ASC])
            ->limit($criteria->limit)
            ->all());

        $results = array_map(static fn(array $row) => new ClickedResult([
            'elementId' => (int)$row['elementId'],
            'elementType' => (string)$row['elementType'],
            'siteId' => (int)$row['clickSiteId'],
            'clicks' => (int)$row['clicks'],
            'averagePosition' => round((float)$row['averagePosition'], 2),
        ]), $rows);

        $this->nameResults($results);

        return $results;
    }

    /**
     * Names clicked results in one query per element type and site, so a list of them costs no more
     * lookups than a single one. A result that can no longer be read keeps its count and loses its
     * name, rather than taking the reading down with it.
     *
     * @param ClickedResult[] $results
     */
    private function nameResults(array $results): void
    {
        $wanted = [];

        foreach ($results as $result) {
            $wanted[$result->elementType][$result->siteId][] = $result->elementId;
        }

        $named = [];

        foreach ($wanted as $elementType => $sites) {
            if (!is_subclass_of($elementType, ElementInterface::class)) {
                continue;
            }

            foreach ($sites as $siteId => $ids) {
                foreach ($elementType::find()->id($ids)->siteId($siteId)->status(null)->all() as $element) {
                    $named[$elementType . ':' . $siteId . ':' . $element->id] = $element;
                }
            }
        }

        foreach ($results as $result) {
            $element = $named[$result->elementType . ':' . $result->siteId . ':' . $result->elementId] ?? null;

            if ($element !== null) {
                $result->label = (string)$element;
                $result->cpEditUrl = $element->getCpEditUrl();
            }
        }
    }

    /**
     * The searches themselves, newest first, for a page that lists what has been happening.
     *
     * @return AnalyticsEvent[]
     */
    public function getRecentSearches(InsightsCriteria $criteria, int $offset = 0): array
    {
        $rows = $this->events($criteria)
            ->select(['id', 'indexId', 'siteId', 'query', 'normalizedQuery', 'correctedQuery', 'language',
                'resultCount', 'executionTime', 'clickCount', 'dateCreated', 'uid', ])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($criteria->limit)
            ->offset(max(0, $offset))
            ->all();

        return array_map(static fn(array $row) => new AnalyticsEvent([
            'id' => (int)$row['id'],
            'indexId' => (int)$row['indexId'],
            'siteId' => $row['siteId'] !== null ? (int)$row['siteId'] : null,
            'query' => (string)$row['query'],
            'normalizedQuery' => (string)$row['normalizedQuery'],
            'correctedQuery' => $row['correctedQuery'] !== null ? (string)$row['correctedQuery'] : null,
            'language' => (string)$row['language'],
            'resultCount' => (int)$row['resultCount'],
            'executionTime' => (float)$row['executionTime'],
            'clickCount' => (int)$row['clickCount'],
            'dateCreated' => DateTimeHelper::toDateTime($row['dateCreated']) ?: null,
            'uid' => (string)$row['uid'],
        ]), $rows);
    }

    public function getSearchCount(InsightsCriteria $criteria): int
    {
        return (int)$this->events($criteria)->count();
    }

    /**
     * One grouped reading of the queries in range. Every query metric is this with a different
     * condition, so they cannot disagree about what a search or a click is.
     *
     * @param array<string,mixed>|null $where
     * @param callable(Query):void|null $refine
     * @return QueryInsight[]
     */
    private function queries(InsightsCriteria $criteria, string $metric, ?array $where = null, ?callable $refine = null): array
    {
        /** @var array<int,array<string,mixed>> $rows */
        $rows = $this->cached($criteria->cacheKey($metric), function() use ($criteria, $where, $refine) {
            $builder = $this->events($criteria)
                ->select([
                    'normalizedQuery',
                    'searches' => 'COUNT(*)',
                    'zeroResults' => $this->countWhere('[[resultCount]] = 0'),
                    'clicks' => 'COALESCE(SUM([[clickCount]]), 0)',
                    'clickedSearches' => $this->countWhere('[[clickCount]] > 0'),
                    'averageTime' => 'AVG([[executionTime]])',
                ])
                ->groupBy(['normalizedQuery'])
                ->orderBy([new Expression('COUNT(*) DESC'), 'normalizedQuery' => SORT_ASC])
                ->limit($criteria->limit);

            if ($where !== null) {
                $builder->andWhere($where);
            }

            if ($refine !== null) {
                $refine($builder);
            }

            return $builder->all();
        });

        return array_map(static fn(array $row) => new QueryInsight([
            'query' => (string)$row['normalizedQuery'],
            'searches' => (int)$row['searches'],
            'zeroResults' => (int)$row['zeroResults'],
            'clicks' => (int)$row['clicks'],
            'clickedSearches' => (int)$row['clickedSearches'],
            'averageTime' => round((float)$row['averageTime'], 3),
        ]), $rows);
    }

    /**
     * The searches a reading covers. A site filter means searches of that site alone: one covering
     * every site was not a search of any one of them.
     */
    private function events(InsightsCriteria $criteria, string $alias = ''): Query
    {
        // Named only when something is joined to it, so every other reading keeps its plain columns.
        $column = $alias !== '' ? $alias . '.' : '';
        $query = (new Query())->from($alias !== '' ? [$alias => Table::SEARCHEVENTS] : [Table::SEARCHEVENTS]);

        if ($criteria->indexId !== null) {
            $query->andWhere([$column . 'indexId' => $criteria->indexId]);
        }

        if ($criteria->siteId !== null) {
            $query->andWhere([$column . 'siteId' => $criteria->siteId]);
        }

        if ($criteria->dateFrom !== null) {
            $query->andWhere(['>=', $column . 'dateCreated', Db::prepareDateForDb($criteria->dateFrom)]);
        }

        // Exclusive, so asking for a day counts the whole of it rather than only its first instant.
        if ($criteria->dateTo !== null) {
            $query->andWhere(['<', $column . 'dateCreated', Db::prepareDateForDb($criteria->dateTo)]);
        }

        return $query;
    }

    /**
     * A conditional count both databases read the same way.
     */
    private function countWhere(string $condition): Expression
    {
        return new Expression("SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END)");
    }

    /**
     * @template T
     * @param callable():T $read
     * @return T
     */
    private function cached(string $key, callable $read): mixed
    {
        $cache = Craft::$app->getCache();
        // Kept against what had been recorded, so a reading can be reused but never be behind.
        $key .= ':' . $this->version();
        $value = $cache->get($key);

        if ($value === false) {
            $value = $read();
            $cache->set($key, $value, self::CACHE_DURATION, new TagDependency(['tags' => Analytics::CACHE_TAG]));
        }

        return $value;
    }

    /**
     * What has been recorded so far, as something that changes the moment anything is — a search,
     * or a result opened. Two lookups of a primary key, and never held on to: a process that goes
     * on recording searches must not go on reading its own first answer.
     *
     * Every page reads the same searches through it, so no two of them can disagree about how many
     * there are, whatever each of them last happened to cache.
     */
    private function version(): string
    {
        return implode(':', [
            (new Query())->from([Table::SEARCHEVENTS])->max('[[id]]') ?? '0',
            (new Query())->from([Table::SEARCHCLICKS])->max('[[id]]') ?? '0',
        ]);
    }
}
