<?php

namespace Tahadudhiya\SearchKit\enums;

/**
 * What changed between one period of search activity and the one before it. Each is a comparison
 * of two recorded windows, never a prediction.
 */
enum AnomalyType: string
{
    case ZeroResults = 'zeroResults';
    case Volume = 'volume';
    case ResponseTime = 'responseTime';

    public function label(): string
    {
        return match ($this) {
            self::ZeroResults => 'Searches finding nothing',
            self::Volume => 'Search volume',
            self::ResponseTime => 'Response time',
        };
    }
}
