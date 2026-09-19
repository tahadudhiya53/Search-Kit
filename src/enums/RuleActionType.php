<?php

namespace Tahadudhiya\SearchKit\enums;

/**
 * What a matching rule does to the results. Every action is deliberate and visible; none of them
 * changes how the query itself is read.
 */
enum RuleActionType: string
{
    case Boost = 'boost';
    case Bury = 'bury';
    case Hide = 'hide';
    case Pin = 'pin';
    case Promote = 'promote';
    case Redirect = 'redirect';

    public function label(): string
    {
        return match ($this) {
            self::Boost => 'Boost',
            self::Bury => 'Bury',
            self::Hide => 'Hide',
            self::Pin => 'Pin',
            self::Promote => 'Promote',
            self::Redirect => 'Redirect',
        };
    }

    /**
     * Whether the action names an element. Only a redirect does not.
     */
    public function targetsElement(): bool
    {
        return $this !== self::Redirect;
    }

    /**
     * Whether the action moves a result by changing its score rather than by placing it.
     */
    public function adjustsScore(): bool
    {
        return $this === self::Boost || $this === self::Bury;
    }

    /**
     * Whether the action puts an element in the results whether or not the search matched it.
     */
    public function inserts(): bool
    {
        return $this === self::Pin || $this === self::Promote;
    }
}
