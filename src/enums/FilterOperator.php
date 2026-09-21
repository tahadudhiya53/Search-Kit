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
    case Between = 'between';

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
            self::Between,
        ], true);
    }

    /**
     * Whether the operator compares against a list rather than a single value.
     */
    public function expectsArray(): bool
    {
        return in_array($this, [self::In, self::NotIn, self::Between], true);
    }

    /**
     * Whether the operator takes a lower and an upper bound rather than a list of any length.
     */
    public function expectsRange(): bool
    {
        return $this === self::Between;
    }
}
