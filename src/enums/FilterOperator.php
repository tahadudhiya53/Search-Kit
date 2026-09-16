<?php

namespace Tahadudhiya\SearchKit\enums;

/**
 * Comparison primitives every provider can be expected to express in its own dialect.
 */
enum FilterOperator: string
{
    case Equals = 'eq';
    case NotEquals = 'neq';
    case In = 'in';
    case NotIn = 'notIn';
    case GreaterThan = 'gt';
    case GreaterThanOrEquals = 'gte';
    case LessThan = 'lt';
    case LessThanOrEquals = 'lte';

    /**
     * Whether the operator orders two values rather than matching them.
     */
    public function isComparison(): bool
    {
        return in_array($this, [
            self::GreaterThan,
            self::GreaterThanOrEquals,
            self::LessThan,
            self::LessThanOrEquals,
        ], true);
    }

    /**
     * Whether the operator compares against a list rather than a single value.
     */
    public function expectsArray(): bool
    {
        return $this === self::In || $this === self::NotIn;
    }
}
