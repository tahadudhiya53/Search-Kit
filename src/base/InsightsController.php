<?php

namespace Tahadudhiya\SearchKit\base;

use Craft;
use craft\web\Controller;
use Tahadudhiya\SearchKit\models\InsightsCriteria;
use Tahadudhiya\SearchKit\SearchKit;

/**
 * Shared ground for the pages that read recorded search activity. Each of them names the indexes
 * and sites its rows refer to, and judges a search slow the same way, so none of them can disagree.
 */
abstract class InsightsController extends Controller
{
    /**
     * Index names by ID, resolved once rather than per row so a long table costs no more lookups.
     *
     * @return array<int,string>
     */
    protected function indexNames(): array
    {
        $names = [];

        foreach (SearchKit::instance()->getIndexes()->getAllIndexes() as $index) {
            $names[(int)$index->id] = $index->name;
        }

        return $names;
    }

    /**
     * @return array<int,string>
     */
    protected function siteNames(): array
    {
        $names = [];

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $names[(int)$site->id] = $site->name;
        }

        return $names;
    }

    /**
     * What counts as slow. One index is measured against its own setting; a reading covering several
     * has no one setting to use, so the default stands.
     */
    protected function slowThreshold(InsightsCriteria $criteria): int
    {
        if ($criteria->indexId === null) {
            return $criteria->slowThreshold;
        }

        $index = SearchKit::instance()->getIndexes()->getIndexById($criteria->indexId);

        return $index?->getAnalyticsSettings()->slowThreshold ?? $criteria->slowThreshold;
    }
}
