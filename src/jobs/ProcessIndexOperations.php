<?php

namespace Tahadudhiya\SearchKit\jobs;

use craft\helpers\Queue;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use Tahadudhiya\SearchKit\enums\IndexOperationStatus;
use Tahadudhiya\SearchKit\SearchKit;

/**
 * Works through an index's outstanding operations, so content changes never index inside the
 * request that made them.
 */
class ProcessIndexOperations extends BaseJob
{
    /** @var int How often a job steps aside for whoever holds the index before giving up. */
    public const MAX_DEFERRALS = 10;

    public const DEFERRAL_DELAY = 30;

    public int $indexId = 0;

    /** @var int How often this job has already stepped aside. */
    public int $deferrals = 0;

    public function execute($queue): void
    {
        $plugin = SearchKit::getInstance();
        $index = $plugin?->getIndexes()->getIndexById($this->indexId);

        if ($plugin === null || $index === null) {
            return;
        }

        $result = $plugin->getIndexing()->processPending($index, function(int $done, int $total) use ($queue) {
            $this->setProgress($queue, $total > 0 ? $done / $total : 1);
        });

        // A rebuild or another worker owns the index. Come back rather than dropping the work.
        if ($result->locked) {
            if ($this->deferrals < self::MAX_DEFERRALS) {
                Queue::push(
                    new self(['indexId' => $this->indexId, 'deferrals' => $this->deferrals + 1]),
                    delay: self::DEFERRAL_DELAY,
                );
            }

            return;
        }

        // Work added while this job was running, or left pending by a failed attempt, gets its own
        // run. A run that touched nothing — a disabled index, say — is never chased, or a job that
        // can never drain its index would queue itself forever.
        $touched = $result->processed + $result->failed;

        if ($touched > 0 && $plugin->getIndexOperations()->countByStatus($this->indexId, IndexOperationStatus::Pending) > 0) {
            Queue::push(new self(['indexId' => $this->indexId]));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('search-kit', 'Updating search indexes');
    }
}
