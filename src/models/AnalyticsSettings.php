<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * What an index records about the searches it runs, and how long it keeps it. Stored with the index
 * it belongs to, so one index can be measured while another is not.
 */
class AnalyticsSettings extends Model
{
    public const MAX_RETENTION_DAYS = 3650;
    public const MAX_SLOW_THRESHOLD = 60000;

    /** @var bool Whether searches of this index are recorded at all. */
    public bool $enabled = true;

    /** @var bool Whether a result someone opened can be associated with the search that found it. */
    public bool $trackClicks = true;

    /** @var int How many days recorded searches are kept for. There is no “keep everything”. */
    public int $retentionDays = 90;

    /** @var int Milliseconds beyond which a search counts as slow. */
    public int $slowThreshold = 500;

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
     * What somebody posted. Nothing is coerced here, so a mistake is reported rather than quietly
     * saved as something else.
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
                    $settings->_rejected[$name] = "“{$name}” is not an analytics setting.";
                }

                continue;
            }

            $read = match ($name) {
                'retentionDays', 'slowThreshold' => self::readInteger($value),
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
     * A real boolean, or the `1` and empty string a control panel switch posts.
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

    private static function readInteger(mixed $value): ?int
    {
        if (is_bool($value) || (!is_int($value) && !is_string($value))) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? null : $integer;
    }

    /**
     * @return array<string,mixed>
     */
    public function toConfig(): array
    {
        return $this->getAttributes();
    }

    protected function defineRules(): array
    {
        return [
            [['retentionDays'], 'integer', 'min' => 1, 'max' => self::MAX_RETENTION_DAYS],
            [['slowThreshold'], 'integer', 'min' => 1, 'max' => self::MAX_SLOW_THRESHOLD],
            // Values that never reached a property at all, because they could not be read.
            [['enabled'], 'validateInput', 'skipOnEmpty' => false],
        ];
    }

    public function validateInput(): void
    {
        foreach ($this->_rejected as $name => $message) {
            $this->addError(property_exists($this, $name) ? $name : 'enabled', $message);
        }
    }
}
