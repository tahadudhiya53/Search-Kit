<?php

namespace Tahadudhiya\SearchKit\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\SearchKit\errors\SearchKitException;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\SearchKit;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Runs a search and shows what it did. Everything shown is recorded by the search itself, so the
 * page explains the search that ran rather than a reconstruction of it.
 */
class DebugController extends Controller
{
    /** @var int Results explained at once. A debugger reads a page, not a whole result set. */
    private const MAX_LIMIT = 100;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(SearchKit::PERMISSION_DEBUG);

        return true;
    }

    public function actionIndex(): Response
    {
        $indexes = $this->plugin()->getIndexes()->getAllIndexes();
        $handle = (string)$this->request->getQueryParam('index', $indexes[0]->handle ?? '');
        $text = trim((string)$this->request->getQueryParam('q', ''));

        $result = null;
        $error = null;

        if ($handle !== '' && $text !== '') {
            try {
                $result = $this->plugin()->getDebugger()->run($this->query($handle, $text));
            } catch (SearchKitException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->renderTemplate('search-kit/debug/_index', [
            'indexes' => $indexes,
            'index' => $this->indexByHandle($indexes, $handle),
            'handle' => $handle,
            'text' => $text,
            'siteId' => $this->siteId(),
            'status' => $this->status(),
            'limit' => $this->limit(),
            'result' => $result,
            'debug' => $result?->debug,
            'error' => $error,
            'sites' => Craft::$app->getSites()->getAllSites(true),
        ]);
    }

    /**
     * The search to run, built the same way a template's would be, so the debugger cannot exercise
     * a path a real search does not take.
     */
    private function query(string $handle, string $text): SearchQuery
    {
        return SearchQuery::create($handle, $text, array_filter([
            'limit' => $this->limit(),
            'siteId' => $this->siteId(),
            'status' => $this->status(),
        ], static fn(mixed $value) => $value !== null));
    }

    private function limit(): int
    {
        $limit = (int)$this->request->getQueryParam('limit', 20);

        return max(1, min($limit, self::MAX_LIMIT));
    }

    private function siteId(): ?int
    {
        $siteId = (int)$this->request->getQueryParam('siteId');

        return $siteId > 0 ? $siteId : null;
    }

    private function status(): ?string
    {
        $status = trim((string)$this->request->getQueryParam('status', ''));

        return $status !== '' ? $status : null;
    }

    /**
     * @param SearchIndex[] $indexes
     */
    private function indexByHandle(array $indexes, string $handle): ?SearchIndex
    {
        foreach ($indexes as $index) {
            if ($index->handle === $handle) {
                return $index;
            }
        }

        return null;
    }

    private function plugin(): SearchKit
    {
        $plugin = SearchKit::getInstance();

        if ($plugin === null) {
            throw new ForbiddenHttpException('Search Kit is not installed.');
        }

        return $plugin;
    }
}
