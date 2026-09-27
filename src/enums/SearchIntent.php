<?php

namespace Tahadudhiya\SearchKit\enums;

/**
 * What a query appears to be after. Read from the words themselves, so it describes the wording
 * rather than the person, and says nothing at all where the wording gives nothing away.
 */
enum SearchIntent: string
{
    case Product = 'product';
    case Informational = 'informational';
    case Support = 'support';
    case Transactional = 'transactional';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Informational => 'Informational',
            self::Support => 'Support',
            self::Transactional => 'Transactional',
            self::Unknown => 'Not apparent',
        };
    }

    /**
     * Whether anything was read from the query at all, which is what separates a reading from none.
     */
    public function isKnown(): bool
    {
        return $this !== self::Unknown;
    }
}
