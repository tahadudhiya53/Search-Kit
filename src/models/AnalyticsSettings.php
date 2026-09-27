<?php

namespace Tahadudhiya\SearchKit\models;

/**
 * What an index records about the searches it runs, how long it keeps it, and what is read back out
 * of it. Stored with the index it belongs to, so one index can be measured while another is not.
 */
class AnalyticsSettings extends StoredSettings
{
    public const MAX_RETENTION_DAYS = 3650;
    public const MAX_SLOW_THRESHOLD = 60000;
    public const MAX_SUGGESTION_MIN_SEARCHES = 10000;
    public const MAX_SUGGESTION_MIN_DAYS = 365;

    /** @var bool Whether searches of this index are recorded at all. */
    public bool $enabled = true;

    /** @var bool Whether a result someone opened can be associated with the search that found it. */
    public bool $trackClicks = true;

    /** @var int How many days recorded searches are kept for. There is no “keep everything”. */
    public int $retentionDays = 90;

    /** @var int Milliseconds beyond which a search counts as slow. */
    public int $slowThreshold = 500;

    /**
     * @var bool Whether what other people have searched for may be offered back as a completion.
     * Off unless it is asked for: it shows one visitor's wording to the next, and only the checks
     * behind it make that safe.
     */
    public bool $suggestPopularQueries = false;

    /**
     * @var int Searches a query needs before it may be offered back. SearchKit does not know who
     * searched, so this is a threshold on repetition — not evidence that more than one person did.
     */
    public int $suggestionMinSearches = 5;

    /** @var int Separate days those searches must span, for the same reason. */
    public int $suggestionMinDays = 2;

    protected static function describe(): string
    {
        return 'an analytics setting';
    }

    protected static function fallbackAttribute(): string
    {
        return 'enabled';
    }

    protected static function readValue(string $name, mixed $value): mixed
    {
        return match ($name) {
            'retentionDays', 'slowThreshold', 'suggestionMinSearches',
            'suggestionMinDays' => self::readInteger($value),
            default => self::readBoolean($value),
        };
    }

    protected function defineRules(): array
    {
        return [
            [['retentionDays'], 'integer', 'min' => 1, 'max' => self::MAX_RETENTION_DAYS],
            [['slowThreshold'], 'integer', 'min' => 1, 'max' => self::MAX_SLOW_THRESHOLD],
            [['suggestionMinSearches'], 'integer', 'min' => 2, 'max' => self::MAX_SUGGESTION_MIN_SEARCHES],
            [['suggestionMinDays'], 'integer', 'min' => 1, 'max' => self::MAX_SUGGESTION_MIN_DAYS],
            // Values that never reached a property at all, because they could not be read.
            [['enabled'], 'validateInput', 'skipOnEmpty' => false],
        ];
    }
}
