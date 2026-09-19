<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use Tahadudhiya\SearchKit\models\ParsedQuery;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\services\Suggestions;

/**
 * Suggestions read the words an index holds, which is a database. Tests that care set an answer.
 */
class StubSuggestions extends Suggestions
{
    public ?ParsedQuery $correction = null;

    /** @var string[] */
    public array $alternatives = [];

    /** @var string[] */
    public array $completions = [];

    public function autocomplete(SearchIndex $index, string $text, int $limit = 5, ?int $siteId = null): array
    {
        return $this->completions;
    }

    public function correct(ParsedQuery $parsed, SearchIndex $index, ?int $siteId = null): ?ParsedQuery
    {
        return $this->correction;
    }

    public function forQuery(ParsedQuery $parsed, SearchIndex $index, ?int $siteId = null, int $limit = 5): array
    {
        return $this->alternatives;
    }
}
