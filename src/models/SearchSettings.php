<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use Tahadudhiya\SearchKit\enums\PartialMatchMode;

/**
 * How an index treats the text it is searched with. Stored with the index it belongs to, so two
 * indexes over the same content can behave differently.
 */
class SearchSettings extends Model
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

    /** @var string[] Settings whose given value was not usable, reported when this is validated. */
    private array $_rejected = [];

    /**
     * Whatever was stored, which may predate a setting or have been edited by hand. A value that
     * cannot be read falls back to the default rather than failing to open the index.
     *
     * @param array<string,mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        return self::read($config, false);
    }

    /**
     * What somebody posted. Nothing is coerced here: `"maybe"` is not `true` and `"12.7"` is not
     * `12`, so a mistake is reported rather than silently saved as something else.
     *
     * @param array<string,mixed> $input
     */
    public static function fromInput(array $input): self
    {
        return self::read($input, true);
    }

    /**
     * @param array<string,mixed> $values
     * @param bool $strict Whether an unusable value is an error or simply left at its default.
     */
    private static function read(array $values, bool $strict): self
    {
        $settings = new self();

        foreach ($values as $name => $value) {
            if (!property_exists($settings, $name) || str_starts_with($name, '_')) {
                if ($strict) {
                    $settings->_rejected[$name] = "“{$name}” is not a search setting.";
                }

                continue;
            }

            $read = match ($name) {
                'partialMatching' => PartialMatchMode::tryFrom(is_string($value) ? $value : ''),
                'customStopWords' => self::readWords($value),
                'minPartialLength', 'typoMinLength', 'typoMaxDistance', 'suggestionLimit' => self::readInteger($value),
                default => self::readBoolean($value),
            };

            if ($read === null) {
                if ($strict) {
                    $settings->_rejected[$name] = "“{$name}” was not given a usable value.";
                }

                continue;
            }

            $settings->$name = $read;
        }

        return $settings;
    }

    /**
     * A real boolean, or the `1` and empty string a control panel switch posts. `'false'` and
     * `'yes'` each have two plausible readings, so neither is guessed at.
     */
    private static function readBoolean(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1' => true,
            $value === 0, $value === '0', $value === '' => false,
            default => null,
        };
    }

    /**
     * A whole number, or the string form of one, since that is how a form posts it.
     */
    private static function readInteger(mixed $value): ?int
    {
        if (is_bool($value) || (!is_int($value) && !is_string($value))) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? null : $integer;
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

    public function validateInput(): void
    {
        foreach ($this->_rejected as $name => $message) {
            $this->addError(property_exists($this, $name) ? $name : 'operators', $message);
        }
    }
}
