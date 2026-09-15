<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\services\SearchableFields;

class StubSearchableFields extends SearchableFields
{
    public function attachFields(SearchIndex $index): void
    {
    }
}
