<?php

namespace Tahadudhiya\SearchKit\enums;

/**
 * Whether a synonym's terms stand in for each other, or only expand in one direction.
 */
enum SynonymType: string
{
    case TwoWay = 'twoWay';
    case OneWay = 'oneWay';

    public function label(): string
    {
        return match ($this) {
            self::TwoWay => 'Two-way',
            self::OneWay => 'One-way',
        };
    }
}
