<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * What one indexing run actually achieved. Callers need to tell a complete run from one that was
 * locked out or left failures behind, so they never report success that did not happen.
 */
class IndexingResult extends Model
{
    /** @var bool Another worker owns this index, so nothing was attempted. */
    public bool $locked = false;

    /** @var bool The configuration changed while this run was working, so it settled nothing. */
    public bool $superseded = false;

    public int $processed = 0;
    public int $failed = 0;
    public int $total = 0;

    public static function locked(): self
    {
        return new self(['locked' => true]);
    }

    /**
     * Whether everything the run set out to do succeeded.
     */
    public function isComplete(): bool
    {
        return !$this->locked && !$this->superseded && $this->failed === 0;
    }
}
