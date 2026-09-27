<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use Tahadudhiya\SearchKit\enums\RecommendationType;

/**
 * Something an administrator could do, with the recorded numbers it was read from. Nothing here is
 * applied automatically: a recommendation is an argument, and the evidence is the argument.
 */
class Recommendation extends Model
{
    public RecommendationType $type = RecommendationType::ImproveContent;

    /** @var string What this is about — the query, or the two queries. */
    public string $subject = '';

    /** @var string Why it is being recommended, in the numbers behind it. */
    public string $reason = '';

    /** @var array<string,string> Label => value, the measurements the reason was read from. */
    public array $evidence = [];

    /** @var string|null Where to go and act on it, where there is somewhere to go. */
    public ?string $url = null;

    /** @var int Searches behind it, which is how one recommendation is weighed against another. */
    public int $searches = 0;
}
