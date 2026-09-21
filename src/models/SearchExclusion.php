<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * One thing SearchKit itself kept out of a search, and why. A rule's target is listed because the
 * rule removed it, not because the provider would have returned it — only the entries a search
 * actually produced can be said to have matched.
 */
class SearchExclusion extends Model
{
    /** @var string A rule removed it from the search entirely. */
    public const HIDDEN_BY_RULE = 'hiddenByRule';

    /** @var string The search produced it, but it could not be shown in the site and status asked for. */
    public const NOT_AVAILABLE = 'notAvailable';

    public int $elementId = 0;
    public ?int $siteId = null;
    public ?string $elementType = null;

    /** @var string|null What the element is called, filled in only where it could be read. */
    public ?string $title = null;

    /** @var string|null The element's own status in Craft, which is why it may be missing. */
    public ?string $status = null;

    public string $reason = self::NOT_AVAILABLE;

    /** @var int|null The rule that removed or placed it, where one did. */
    public ?int $ruleId = null;
}
