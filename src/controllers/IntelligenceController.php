<?php

namespace Tahadudhiya\SearchKit\controllers;

use Craft;
use Tahadudhiya\SearchKit\base\InsightsController;
use Tahadudhiya\SearchKit\enums\SearchIntent;
use Tahadudhiya\SearchKit\models\InsightsCriteria;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\SearchKit;
use Tahadudhiya\SearchKit\services\Intelligence;
use Throwable;
use yii\web\Response;

/**
 * What the recorded searches suggest somebody should do. It reads activity that was already
 * recorded and never runs a search, so opening this page changes nothing about search.
 */
class IntelligenceController extends InsightsController
{
    /** @var int Rows each reading on the page shows. */
    private const ROWS = 10;

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
        $plugin = SearchKit::instance();
        $criteria = InsightsCriteria::fromRequest($this->request->getQueryParams());
        $criteria->limit = self::ROWS;

        $index = $criteria->indexId !== null ? $plugin->getIndexes()->getIndexById($criteria->indexId) : null;

        return $this->renderTemplate('search-kit/intelligence/_index', array_merge([
            'criteria' => $criteria,
            'index' => $index,
            'indexNames' => $this->indexNames(),
            'siteNames' => $this->siteNames(),
            'intentLabels' => $this->intentLabels(),
            'windowDays' => Intelligence::WINDOW_DAYS,
        ], $this->readings($criteria, $index)));
    }

    /**
     * Everything the page reads. A reading that cannot be taken leaves the page standing and says
     * so, rather than turning a failure into a page saying there is nothing to do.
     *
     * @return array<string,mixed>
     */
    private function readings(InsightsCriteria $criteria, ?SearchIndex $index): array
    {
        $plugin = SearchKit::instance();

        try {
            return [
                // Which indexes follow clicks, and what each one calls slow, are settled by the
                // service: they are properties of the indexes being read, not of the request.
                'quality' => $plugin->getIntelligence()->getQualityScore($criteria),
                'anomalies' => $plugin->getIntelligence()->detectAnomalies($criteria),
                'recommendations' => $plugin->getRecommendations()->forCriteria($criteria, $index),
                'intents' => $plugin->getIntelligence()->getIntentBreakdown($criteria),
                'unavailable' => false,
            ];
        } catch (Throwable $e) {
            Craft::error("Search intelligence could not be read: {$e->getMessage()}", SearchKit::LOG_CATEGORY);

            return [
                'quality' => null,
                'anomalies' => [],
                'recommendations' => [],
                'intents' => [],
                'unavailable' => true,
            ];
        }
    }

    /**
     * @return array<string,string>
     */
    private function intentLabels(): array
    {
        $labels = [];

        foreach (SearchIntent::cases() as $intent) {
            $labels[$intent->value] = Craft::t('search-kit', $intent->label());
        }

        return $labels;
    }
}
