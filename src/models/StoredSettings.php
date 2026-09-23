<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * Settings stored as JSON on the index they belong to. Reading them from a posted form and reading
 * them back from the column are the same walk over the same properties, so both live here.
 */
abstract class StoredSettings extends Model
{
    /** @var string[] Settings whose given value was not usable, reported when this is validated. */
    private array $_rejected = [];

    /**
     * Fixed, because settings are read onto a fresh instance of whichever kind they are: a subclass
     * needing different construction arguments would leave `read()` unable to make one.
     */
    final public function __construct($config = [])
    {
        parent::__construct($config);
    }

    /**
     * Whatever was stored, which may predate a setting or have been edited by hand. A value that
     * cannot be read falls back to the default rather than failing to open the index.
     *
     * @param array<string,mixed> $config
     */
    public static function fromConfig(array $config): static
    {
        return self::read($config, false);
    }

    /**
     * What somebody posted. Nothing is coerced here, so a mistake is reported rather than quietly
     * saved as something else.
     *
     * @param array<string,mixed> $input
     */
    public static function fromInput(array $input): static
    {
        return self::read($input, true);
    }

    /**
     * What this kind of setting is called, worded to follow “is not”.
     */
    abstract protected static function describe(): string;

    /**
     * The attribute a name nothing could be read for is reported against, having none of its own.
     */
    abstract protected static function fallbackAttribute(): string;

    /**
     * One setting's given value as its property should hold it, or null when it cannot be read.
     */
    abstract protected static function readValue(string $name, mixed $value): mixed;

    /**
     * @param array<string,mixed> $values
     * @param bool $strict Whether an unusable value is an error or simply left at its default.
     */
    private static function read(array $values, bool $strict): static
    {
        $settings = new static();

        foreach ($values as $name => $value) {
            if (!property_exists($settings, $name) || str_starts_with($name, '_')) {
                if ($strict) {
                    $settings->_rejected[$name] = "“{$name}” is not " . static::describe() . '.';
                }

                continue;
            }

            $read = static::readValue($name, $value);

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
    protected static function readBoolean(mixed $value): ?bool
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
    protected static function readInteger(mixed $value): ?int
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

    public function validateInput(): void
    {
        foreach ($this->_rejected as $name => $message) {
            $this->addError(property_exists($this, $name) ? $name : static::fallbackAttribute(), $message);
        }
    }
}
