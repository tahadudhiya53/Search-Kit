<?php

namespace Tahadudhiya\SearchKit\jobs;

use craft\helpers\Queue;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use Tahadudhiya\SearchKit\SearchKit;

/**
 * Reindexes an index from scratch. Queued because a rebuild can easily outlast a web request.
 */
class RebuildIndex extends BaseJob
{
    /** @var int How often a rebuild steps aside for whoever holds the index before giving up. */
    public const MAX_DEFERRALS = 10;

    public const DEFERRAL_DELAY = 60;

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

        $result = $plugin->getIndexing()->rebuild($index, function(int $done, int $total) use ($queue) {
            $this->setProgress($queue, $total > 0 ? $done / $total : 1);
        });

        // Another rebuild or a drain owns the index; two of either would write to it at once.
        if ($result->locked && $this->deferrals < self::MAX_DEFERRALS) {
            Queue::push(
                new self(['indexId' => $this->indexId, 'deferrals' => $this->deferrals + 1]),
                delay: self::DEFERRAL_DELAY,
            );
        }
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('search-kit', 'Rebuilding search index');
    }
}
