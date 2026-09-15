<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\services\Indexes;

/**
 * Serves indexes from memory so the search pipeline can be tested without a database.
 */
class StubIndexes extends Indexes
{
    /** @var SearchIndex[] */
    public array $indexes = [];

    public function getAllIndexes(): array
    {
        return $this->indexes;
    }
}
