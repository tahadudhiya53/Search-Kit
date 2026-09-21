<?php

namespace Tahadudhiya\SearchKit\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\SearchKit\models\DashboardLayout;
use Tahadudhiya\SearchKit\models\InsightsCriteria;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * An overview of how search is doing: what was searched for, what came of it, and how the indexes
 * serving it are holding up. It reads recorded activity and never runs a search.
 */
class DashboardController extends Controller
{
    /** @var int Rows each table on the overview shows. Everything else is a page away. */
    private const TABLE_ROWS = 8;

    /** @var int Points the activity chart draws at most, after which days are grouped. */
    private const CHART_POINTS = 60;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(SearchKit::PERMISSION_VIEW_INSIGHTS);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = $this->plugin();
        $criteria = InsightsCriteria::fromRequest($this->request->getQueryParams());
        $criteria->limit = self::TABLE_ROWS;
        $criteria->slowThreshold = $criteria->indexId !== null
            ? ($plugin->getIndexes()->getIndexById($criteria->indexId)?->getAnalyticsSettings()->slowThreshold
                ?? $criteria->slowThreshold)
            : $criteria->slowThreshold;

        $indexes = $plugin->getIndexes()->getAllIndexes();

        // Names are resolved once rather than per row, so a long table costs no more lookups.
        $indexNames = [];

        foreach ($indexes as $index) {
            $indexNames[(int)$index->id] = $index->name;
        }

        $siteNames = [];

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $siteNames[(int)$site->id] = $site->name;
        }

        return $this->renderTemplate('search-kit/dashboard/_index', array_merge([
            'criteria' => $criteria,
            'layout' => $this->layout(),
            // Arranging is a mode of the page rather than a page of its own, so the filters hold.
            'arranging' => (string)$this->request->getQueryParam('layout') === 'arrange',
            'health' => $this->health(),
            'indexNames' => $indexNames,
            'siteNames' => $siteNames,
            'minSearches' => $criteria->minSearches,
        ], $this->activity($criteria)));
    }

    /**
     * Moves, resizes, puts away or brings back one panel of the person's own dashboard. Nothing
     * here decides what anybody may see — only how they have arranged what they already can.
     */
    public function actionArrange(): Response
    {
        $this->requirePostRequest();

        $userId = Craft::$app->getUser()->getId();
        $layout = $this->layout();
        $panel = (string)$this->request->getBodyParam('panel', '');

        switch ((string)$this->request->getBodyParam('operation')) {
            case 'order':
                $posted = $this->request->getBodyParam('panels');
                $layout->reorder(is_array($posted) ? $posted : []);
                break;
            case 'span':
                $layout->setSpan($panel, (string)$this->request->getBodyParam('span', ''));
                break;
            case 'hide':
                $layout->setHidden($panel, true);
                break;
            case 'show':
                $layout->setHidden($panel, false);
                break;
            default:
                return $this->asFailure(Craft::t('search-kit', 'That is not something a panel can do.'));
        }

        if ($userId === null || !$this->plugin()->getDashboardLayouts()->save($userId, $layout)) {
            return $this->asFailure(Craft::t('search-kit', 'Couldn’t save your dashboard arrangement.'));
        }

        return $this->asSuccess(Craft::t('search-kit', 'Dashboard arrangement saved.'));
    }

    /**
     * Puts this person's dashboard back the way it started.
     */
    public function actionResetLayout(): Response
    {
        $this->requirePostRequest();

        $userId = Craft::$app->getUser()->getId();

        if ($userId !== null) {
            $this->plugin()->getDashboardLayouts()->reset($userId);
        }

        return $this->asSuccess(Craft::t('search-kit', 'Dashboard arrangement reset.'));
    }

    /**
     * How this person has arranged their dashboard, or the arrangement everybody starts with.
     */
    private function layout(): DashboardLayout
    {
        $userId = Craft::$app->getUser()->getId();

        return $userId !== null
            ? $this->plugin()->getDashboardLayouts()->getForUser($userId)
            : DashboardLayout::fromConfig(null);
    }

    /**
     * Everything the overview reads about search activity. A reading that cannot be taken leaves the
     * page standing and says so, rather than turning a failure into an empty dashboard.
     *
     * @return array<string,mixed>
     */
    private function activity(InsightsCriteria $criteria): array
    {
        $insights = $this->plugin()->getInsights();

        try {
            return [
                'summary' => $insights->getSummary($criteria),
                'trend' => $insights->condenseTrend($insights->getTrend($criteria), self::CHART_POINTS),
                'popularQueries' => $insights->getPopularQueries($criteria),
                'zeroResultQueries' => $insights->getZeroResultQueries($criteria),
                'unopenedQueries' => $insights->getUnopenedQueries($criteria),
                'slowQueries' => $insights->getSlowQueries($criteria),
                'clickedResults' => $insights->getClickedResults($criteria),
                'unavailable' => false,
            ];
        } catch (Throwable $e) {
            Craft::error("Search activity could not be read: {$e->getMessage()}", SearchKit::LOG_CATEGORY);

            return [
                'summary' => null,
                'trend' => [],
                'popularQueries' => [],
                'zeroResultQueries' => [],
                'unopenedQueries' => [],
                'slowQueries' => [],
                'clickedResults' => [],
                'unavailable' => true,
            ];
        }
    }

    /**
     * Whether search is configured and serving, read from the indexes themselves.
     *
     * @return array<int,array<string,mixed>>
     */
    private function health(): array
    {
        $plugin = $this->plugin();
        $health = [];

        foreach ($plugin->getIndexes()->getAllIndexes() as $index) {
            $health[] = [
                'index' => $index,
                'status' => $plugin->getIndexing()->getStatus($index),
                'analytics' => $index->getAnalyticsSettings(),
            ];
        }

        return $health;
    }

    private function plugin(): SearchKit
    {
        $plugin = SearchKit::getInstance();

        if ($plugin === null) {
            throw new ForbiddenHttpException('SearchKit is not installed.');
        }

        return $plugin;
    }
}
