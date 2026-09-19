<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use Tahadudhiya\SearchKit\models\SearchRule;
use Tahadudhiya\SearchKit\services\Rules;

/**
 * Rules live in the database, so a unit test sets them in memory instead.
 */
class StubRules extends Rules
{
    /** @var SearchRule[] */
    public array $rules = [];

    public function getAllRules(): array
    {
        return $this->rules;
    }
}
