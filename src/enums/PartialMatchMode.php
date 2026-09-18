<?php

namespace Tahadudhiya\SearchKit\enums;

/**
 * How much of a word a term has to cover to match it.
 */
enum PartialMatchMode: string
{
    case Off = 'off';
    case Prefix = 'prefix';
    case Substring = 'substring';

    public function matchesLeft(): bool
    {
        return $this === self::Substring;
    }

    public function matchesRight(): bool
    {
        return $this !== self::Off;
    }
}
