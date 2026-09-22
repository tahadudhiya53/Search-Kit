<?php

namespace Tahadudhiya\SearchKit\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use Tahadudhiya\SearchKit\models\IndexingResult;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\SearchKit;
use yii\console\Exception;
use yii\console\ExitCode;

/**
 * Runs and inspects SearchKit indexing from the command line.
 */
class IndexController extends Controller
{
    /** @var bool Whether to run the work now instead of handing it to the queue. */
    public bool $now = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if (in_array($actionID, ['rebuild', 'retry'], true)) {
            $options[] = 'now';
        }

        return $options;
    }

    /**
     * Lists every index with what it owes.
     */
    public function actionStatus(): int
    {
        $plugin = $this->plugin();

        foreach ($plugin->getIndexes()->getAllIndexes() as $index) {
            $status = $plugin->getIndexing()->getStatus($index);

            $this->stdout("$index->handle ", Console::FG_YELLOW);
            $this->stdout(sprintf(
                "%s — pending: %d, failed: %d, last indexed: %s%s\n",
                $index->enabled ? 'enabled' : 'disabled',
                $status->pending,
                $status->failed,
                $index->dateLastIndexed?->format('Y-m-d H:i') ?? 'never',
                $status->rebuildRequired ? ', rebuild required' : '',
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Works through everything an index owes.
     */
    public function actionProcess(string $handle): int
    {
        $index = $this->requireIndex($handle);
        $result = $this->plugin()->getIndexing()->processPending($index);

        return $this->report($result, 'Processed');
    }

    /**
     * Reindexes an index from scratch.
     */
    public function actionRebuild(string $handle): int
    {
        $plugin = $this->plugin();
        $index = $this->requireIndex($handle);

        if (!$this->now) {
            $plugin->getIndexing()->queueRebuild($index);
            $this->stdout("Rebuild queued.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $result = $plugin->getIndexing()->rebuild($index, function(int $done, int $total) {
            Console::updateProgress($done, $total);
        });

        Console::endProgress();

        return $this->report($result, 'Indexed');
    }

    /**
     * Puts failed operations back in the queue.
     */
    public function actionRetry(string $handle): int
    {
        $plugin = $this->plugin();
        $index = $this->requireIndex($handle);
        $reset = $plugin->getIndexing()->retryFailed($index);

        $this->stdout("Queued $reset failed operations.\n", Console::FG_GREEN);

        if ($this->now) {
            return $this->report($plugin->getIndexing()->processPending($index), 'Processed');
        }

        return ExitCode::OK;
    }

    /**
     * Reports what a run actually achieved, so a partial one is never read as a success.
     */
    private function report(IndexingResult $result, string $verb): int
    {
        if ($result->locked) {
            $this->stdout("Another process is already working on this index. Nothing was done.\n", Console::FG_YELLOW);

            return ExitCode::TEMPFAIL;
        }

        if ($result->superseded) {
            $this->stdout(
                "The configuration changed while this ran, so the index still needs rebuilding.\n",
                Console::FG_YELLOW,
            );

            return ExitCode::TEMPFAIL;
        }

        $this->stdout("$verb $result->processed elements.\n", Console::FG_GREEN);

        if ($result->failed > 0) {
            $this->stdout(
                "$result->failed could not be indexed and stay outstanding. They will be retried.\n",
                Console::FG_YELLOW,
            );

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function requireIndex(string $handle): SearchIndex
    {
        $index = $this->plugin()->getIndexes()->getIndexByHandle($handle);

        if ($index === null) {
            throw new Exception("No search index exists with the handle “{$handle}”.");
        }

        return $index;
    }

    private function plugin(): SearchKit
    {
        $plugin = SearchKit::getInstance();

        if ($plugin === null) {
            throw new Exception('Search Kit is not installed or is disabled.');
        }

        return $plugin;
    }
}
