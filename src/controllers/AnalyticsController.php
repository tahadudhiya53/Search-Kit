<?php

namespace Tahadudhiya\SearchKit\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\SearchKit\models\InsightsCriteria;
use Tahadudhiya\SearchKit\SearchKit;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Reads what has been searched for, and takes the click a result reports back.
 */
class AnalyticsController extends Controller
{
    /** @var int Searches listed per page. */
    private const PAGE_SIZE = 50;

    // A click is reported from the front end, where there is nobody to have a permission.
    protected array|bool|int $allowAnonymous = ['click' => self::ALLOW_ANONYMOUS_LIVE];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if ($action->id !== 'click') {
            $this->requirePermission(SearchKit::PERMISSION_VIEW_INSIGHTS);
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = $this->plugin();
        $criteria = InsightsCriteria::fromRequest($this->request->getQueryParams());
        $criteria->limit = self::PAGE_SIZE;
        $criteria->slowThreshold = $this->slowThreshold($criteria);

        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $indexes = $plugin->getIndexes()->getAllIndexes();

        // Names are resolved once here rather than per row, so a long list costs no more lookups.
        $indexNames = [];

        foreach ($indexes as $index) {
            $indexNames[(int)$index->id] = $index->name;
        }

        $siteNames = [];

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $siteNames[(int)$site->id] = $site->name;
        }

        return $this->renderTemplate('search-kit/analytics/_index', [
            'criteria' => $criteria,
            'summary' => $plugin->getInsights()->getSummary($criteria),
            'searches' => $plugin->getInsights()->getRecentSearches($criteria, ($page - 1) * self::PAGE_SIZE),
            'total' => $plugin->getInsights()->getSearchCount($criteria),
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'indexNames' => $indexNames,
            'siteNames' => $siteNames,
            'canManage' => Craft::$app->getUser()->checkPermission(SearchKit::PERMISSION_MANAGE_INSIGHTS),
        ]);
    }

    /**
     * Associates a result someone opened with the search that found it. Nothing posted here is
     * trusted: the token has to name a recent search, and the result has to be one that search
     * actually returned, in the site it returned it for.
     */
    public function actionClick(): Response
    {
        $this->requirePostRequest();

        $recorded = $this->plugin()->getAnalytics()->recordClick(
            (string)$this->request->getRequiredBodyParam('token'),
            (int)$this->request->getRequiredBodyParam('elementId'),
            (int)$this->request->getRequiredBodyParam('siteId'),
        );

        return $recorded
            ? $this->asSuccess(Craft::t('search-kit', 'Click recorded.'))
            : $this->asFailure(Craft::t('search-kit', 'That result could not be associated with a search.'));
    }

    /**
     * Forgets recorded searches, for one index or for every one of them.
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(SearchKit::PERMISSION_MANAGE_INSIGHTS);

        $indexId = $this->request->getBodyParam('indexId');
        $deleted = $this->plugin()->getAnalytics()->clear(
            $indexId !== null && $indexId !== '' ? (int)$indexId : null,
        );

        $this->setSuccessFlash(Craft::t('search-kit', '{count} recorded searches deleted.', ['count' => $deleted]));

        return $this->redirect('search-kit/analytics');
    }

    /**
     * What counts as slow. One index is measured against its own setting; a reading covering several
     * has no one setting to use, so the default stands.
     */
    private function slowThreshold(InsightsCriteria $criteria): int
    {
        if ($criteria->indexId === null) {
            return $criteria->slowThreshold;
        }

        $index = $this->plugin()->getIndexes()->getIndexById($criteria->indexId);

        return $index?->getAnalyticsSettings()->slowThreshold ?? $criteria->slowThreshold;
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
