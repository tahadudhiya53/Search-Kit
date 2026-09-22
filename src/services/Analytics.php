<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\caching\TagDependency;
use yii\db\Expression;

/**
 * Where search activity is recorded. Search knows nothing about this: it is reached through the
 * search event, so a search runs the same whether anything is listening or not.
 */
class Analytics extends Component
{
    /** @var int Longer queries are truncated: what is stored is a query, not a document. */
    public const MAX_QUERY_LENGTH = 255;

    /** @var string Everything read from this table carries the tag, so clearing it empties them all. */
    public const CACHE_TAG = 'searchkit:insights';

    /** @var int Hours a search's token may still be clicked. A click long after is not its click. */
    private const CLICK_WINDOW_HOURS = 24;

    /** @var int Results per search a click may name. A window wider than this is not all kept. */
    public const MAX_TRACKED_RESULTS = 100;

    /** @var int Rows deleted per statement while retention is enforced. */
    private const PRUNE_BATCH = 1000;

    private ?Normalization $_normalization = null;
    private ?Indexes $_indexes = null;

    /**
     * Records a search that has finished. Failure is logged and swallowed: a search that worked must
     * not fail because it could not be counted.
     *
     * @return string|null The token a result carries back when it is clicked.
     */
    public function recordSearch(SearchQuery $query, SearchIndex $index, SearchResult $result): ?string
    {
        $settings = $index->getAnalyticsSettings();

        // A search run to diagnose one is not something anybody searched for, so it is not counted.
        if (!$settings->enabled || $index->id === null || $query->isDebugging()) {
            return null;
        }

        $scope = $query->getSiteScope($index->siteId);
        $language = $this->getNormalization()->languageFor($scope);
        $token = StringHelper::UUID();

        try {
            Db::insert(Table::SEARCHEVENTS, [
                'indexId' => $index->id,
                'siteId' => $query->getSiteScopeId($index->siteId),
                'query' => $this->trim($query->text),
                // Reduced in the first of the languages searched, which is what groups two spellings
                // of one query together. It is a grouping key, not a claim about the search.
                'normalizedQuery' => $this->trim($this->getNormalization()->normalize(
                    $query->text,
                    $this->getNormalization()->languagesFor($scope)[0],
                )),
                'correctedQuery' => $result->correctedText !== null ? $this->trim($result->correctedText) : null,
                'language' => $language,
                'resultCount' => $result->total,
                'executionTime' => round($result->executionTime, 3),
                // Only kept when a click could be reported, so nothing is stored for nothing.
                'trackedResults' => $settings->trackClicks ? $this->trackedResults($result) : null,
                'uid' => $token,
            ]);
        } catch (Throwable $e) {
            Craft::warning("Could not record a search of “{$index->handle}”: {$e->getMessage()}", SearchKit::LOG_CATEGORY);

            return null;
        }

        return $settings->trackClicks ? $token : null;
    }

    /**
     * The results a search returned, as the element and site each of them names. This is what makes
     * a click provable: only a result this search actually gave back may report one.
     */
    private function trackedResults(SearchResult $result): ?string
    {
        $keys = [];

        foreach (array_slice($result->hits, 0, self::MAX_TRACKED_RESULTS) as $hit) {
            $keys[] = [$hit->elementId, (int)$hit->siteId];
        }

        return $keys === [] ? null : Json::encode($keys);
    }

    /**
     * Associates a result someone opened with the search that found it. The token is the only thing
     * linking the two, so a click needs nothing about whoever made it.
     *
     * Everything is re-read from the database rather than believed: the search must exist, be
     * recent, and have returned this very result in this very site, and the element must still be
     * there. Where the result sat is read from the search too, so nothing about a click is posted
     * but which result it was.
     */
    public function recordClick(string $token, int $elementId, int $siteId): bool
    {
        $event = (new Query())
            ->select(['id', 'indexId', 'trackedResults'])
            ->from([Table::SEARCHEVENTS])
            ->where(['uid' => $token])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb(
                DateTimeHelper::now()->modify('-' . self::CLICK_WINDOW_HOURS . ' hours'),
            )])
            ->one();

        if ($event === null) {
            return false;
        }

        $index = $this->getIndexes()->getIndexById((int)$event['indexId']);

        if ($index === null || !$index->getAnalyticsSettings()->enabled || !$index->getAnalyticsSettings()->trackClicks) {
            return false;
        }

        $position = $this->positionOf($event['trackedResults'], $elementId, $siteId);

        if ($position === null) {
            return false;
        }

        $elementType = $this->elementType($elementId);

        if ($elementType === null) {
            return false;
        }

        return $this->storeClick((int)$event['id'], $elementId, $elementType, $siteId, $position);
    }

    /**
     * Where this result sat in what the search returned, counted from one, and null for a result
     * that search never returned — in this site or at all.
     */
    private function positionOf(mixed $stored, int $elementId, int $siteId): ?int
    {
        $keys = is_string($stored) ? Json::decodeIfJson($stored) : null;

        if (!is_array($keys)) {
            return null;
        }

        foreach (array_values($keys) as $position => $key) {
            if (is_array($key) && (int)($key[0] ?? 0) === $elementId && (int)($key[1] ?? 0) === $siteId) {
                return $position + 1;
            }
        }

        return null;
    }

    /**
     * The element's own type, read from Craft rather than taken from the request, and null for one
     * that is gone.
     */
    private function elementType(int $elementId): ?string
    {
        $type = (new Query())
            ->select(['type'])
            ->from([CraftTable::ELEMENTS])
            ->where(['id' => $elementId, 'dateDeleted' => null])
            ->scalar();

        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * One click per result per search. A second report of the same one is accepted and counted
     * once, so a reload cannot inflate a click-through rate.
     */
    private function storeClick(int $eventId, int $elementId, string $elementType, int $siteId, int $position): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $exists = (new Query())
                ->from([Table::SEARCHCLICKS])
                ->where(['eventId' => $eventId, 'elementId' => $elementId, 'siteId' => $siteId])
                ->exists();

            if (!$exists) {
                Db::insert(Table::SEARCHCLICKS, [
                    'eventId' => $eventId,
                    'elementId' => $elementId,
                    'elementType' => $elementType,
                    'siteId' => $siteId,
                    'position' => $position,
                ]);

                // Kept on the search itself so a click-through rate never needs a join.
                Db::update(
                    Table::SEARCHEVENTS,
                    ['clickCount' => new Expression('[[clickCount]] + 1')],
                    ['id' => $eventId],
                );
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            Craft::warning("Could not record a search result click: {$e->getMessage()}", SearchKit::LOG_CATEGORY);

            return false;
        }

        return true;
    }

    /**
     * Enforces every index's retention, which is what keeps this from growing without limit. Run by
     * Craft's own garbage collection, so it needs nothing scheduled.
     *
     * @return int Searches forgotten.
     */
    public function prune(): int
    {
        $deleted = 0;

        foreach ($this->getIndexes()->getAllIndexes() as $index) {
            if ($index->id === null) {
                continue;
            }

            $cutoff = Db::prepareDateForDb(
                DateTimeHelper::now()->modify('-' . $index->getAnalyticsSettings()->retentionDays . ' days'),
            );

            // Deleted in batches so one long-untended index cannot lock the table for the rest.
            do {
                $ids = (new Query())
                    ->select(['id'])
                    ->from([Table::SEARCHEVENTS])
                    ->where(['indexId' => $index->id])
                    ->andWhere(['<', 'dateCreated', $cutoff])
                    ->limit(self::PRUNE_BATCH)
                    ->column();

                if ($ids !== []) {
                    $deleted += Db::delete(Table::SEARCHEVENTS, ['id' => $ids]);
                }
            } while (count($ids) === self::PRUNE_BATCH);
        }

        if ($deleted > 0) {
            $this->invalidate();
        }

        return $deleted;
    }

    /**
     * Forgets recorded searches, for one index or for every one of them. Clicks go with them.
     *
     * @return int Searches forgotten.
     */
    public function clear(?int $indexId = null): int
    {
        $deleted = Db::delete(Table::SEARCHEVENTS, $indexId !== null ? ['indexId' => $indexId] : '');
        $this->invalidate();

        return $deleted;
    }

    /**
     * Everything read from this table is cached briefly, so anything that empties it says so.
     */
    public function invalidate(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
    }

    private function trim(string $text): string
    {
        return mb_substr($text, 0, self::MAX_QUERY_LENGTH);
    }

    public function setNormalization(Normalization $normalization): void
    {
        $this->_normalization = $normalization;
    }

    public function getNormalization(): Normalization
    {
        return $this->_normalization ??= $this->plugin()->getNormalization();
    }

    public function setIndexes(Indexes $indexes): void
    {
        $this->_indexes = $indexes;
    }

    public function getIndexes(): Indexes
    {
        return $this->_indexes ??= $this->plugin()->getIndexes();
    }

    private function plugin(): SearchKit
    {
        $plugin = SearchKit::getInstance();

        if ($plugin === null) {
            throw new InvalidConfigException('Search Kit is not installed or is disabled.');
        }

        return $plugin;
    }
}
