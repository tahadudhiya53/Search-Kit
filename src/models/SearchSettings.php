<?php

namespace Tahadudhiya\SearchKit\models;

use Tahadudhiya\SearchKit\enums\PartialMatchMode;

/**
 * How an index treats the text it is searched with. Stored with the index it belongs to, so two
 * indexes over the same content can behave differently.
 */
class SearchSettings extends StoredSettings
{
    public const MAX_TYPO_DISTANCE = 2;
    public const MAX_SUGGESTIONS = 25;
    public const MAX_LENGTH = 20;

    /** @var bool Whether `"phrases"`, `-exclusions`, `OR` and `*` are read as operators. */
    public bool $operators = true;

    /** @var PartialMatchMode How much of a word a term matches when no operator says otherwise. */
    public PartialMatchMode $partialMatching = PartialMatchMode::Prefix;

    /** @var int Terms shorter than this are matched whole, so `at` does not match everything. */
    public int $minPartialLength = 3;

    /** @var bool Whether words too common to narrow anything down are dropped from a query. */
    public bool $stopWords = true;

    /** @var string[] Words to drop on top of the built-in list. */
    public array $customStopWords = [];

    public bool $synonyms = true;

    /** @var bool Whether a query that found nothing is retried against the terms the index holds. */
    public bool $typoTolerance = true;

    /** @var int Terms shorter than this are never corrected: a short word is rarely a typo. */
    public int $typoMinLength = 4;

    /** @var int How many single-character edits a correction may be away from what was typed. */
    public int $typoMaxDistance = 1;

    /** @var bool Whether a search that found nothing suggests something else to try. */
    public bool $suggestions = true;

    public int $suggestionLimit = 5;

    protected static function describe(): string
    {
        return 'a search setting';
    }

    protected static function fallbackAttribute(): string
    {
        return 'operators';
    }

    protected static function readValue(string $name, mixed $value): mixed
    {
        return match ($name) {
            'partialMatching' => PartialMatchMode::tryFrom(is_string($value) ? $value : ''),
            'customStopWords' => self::readWords($value),
            'minPartialLength', 'typoMinLength', 'typoMaxDistance', 'suggestionLimit' => self::readInteger($value),
            default => self::readBoolean($value),
        };
    }

    /**
     * Words as a list, or as the comma-separated line a form posts.
     *
     * @return string[]|null
     */
    private static function readWords(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return null;
        }

        $words = [];

        foreach ($value as $word) {
            if (!is_string($word)) {
                return null;
            }

            if (trim($word) !== '') {
                $words[] = trim($word);
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * @return array<string,mixed>
     */
    public function toConfig(): array
    {
        return ['partialMatching' => $this->partialMatching->value] + $this->getAttributes(null, ['partialMatching']);
    }

    /**
     * Whether a term is long enough to be matched on part of itself.
     */
    public function allowsPartialMatch(string $term): bool
    {
        return $this->partialMatching !== PartialMatchMode::Off && mb_strlen($term) >= $this->minPartialLength;
    }

    public function allowsCorrection(string $term): bool
    {
        return $this->typoTolerance && mb_strlen($term) >= $this->typoMinLength;
    }

    protected function defineRules(): array
    {
        return [
            [['minPartialLength', 'typoMinLength'], 'integer', 'min' => 1, 'max' => self::MAX_LENGTH],
            [['typoMaxDistance'], 'integer', 'min' => 1, 'max' => self::MAX_TYPO_DISTANCE],
            [['suggestionLimit'], 'integer', 'min' => 1, 'max' => self::MAX_SUGGESTIONS],
            // Values that never reached a property at all, because they could not be read.
            [['operators'], 'validateInput', 'skipOnEmpty' => false],
        ];
    }
}
