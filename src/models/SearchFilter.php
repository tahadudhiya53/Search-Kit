<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use DateTimeInterface;
use Tahadudhiya\SearchKit\enums\FilterOperator;

/**
 * One provider-independent constraint on a search. Providers translate it into their own dialect.
 */
class SearchFilter extends Model
{
    public string $field = '';
    public FilterOperator $operator = FilterOperator::Equals;
    public mixed $value = null;

    public static function make(string $field, FilterOperator $operator, mixed $value): self
    {
        return new self([
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
        ]);
    }

    protected function defineRules(): array
    {
        return [
            [['field'], 'required'],
            [['field'], 'string', 'max' => 255],
            // Empty and null values are exactly what needs rejecting, so they are not skipped.
            [['value'], 'validateValue', 'skipOnEmpty' => false],
        ];
    }

    /**
     * Whether a range runs backwards. Only values with an order that every provider reads the same
     * way are judged: two numbers, two dates, or two dates written out, where text sorts by date.
     * Anything else is left to the provider, which is what keeps this from inventing an ordering.
     */
    private function isInverted(): bool
    {
        [$from, $to] = array_values((array)$this->value) + [null, null];

        if ($from instanceof DateTimeInterface && $to instanceof DateTimeInterface) {
            return $from > $to;
        }

        if (is_numeric($from) && is_numeric($to)) {
            return (float)$from > (float)$to;
        }

        if (is_string($from) && is_string($to) && self::isDateText($from) && self::isDateText($to)) {
            return strcmp($from, $to) > 0;
        }

        return false;
    }

    /**
     * Whether text is an ISO-8601 date, which sorts chronologically as it stands.
     */
    private static function isDateText(string $value): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}([T ]|$)/', $value);
    }

    public function validateValue(string $attribute): void
    {
        if ($this->operator->expectsArray() && !is_array($this->value)) {
            $this->addError($attribute, "The {$this->operator->value} operator expects an array of values.");
            return;
        }

        if (!$this->operator->expectsArray() && is_array($this->value)) {
            $this->addError($attribute, "The {$this->operator->value} operator expects a single value.");
            return;
        }

        if ($this->operator->expectsArray() && $this->value === []) {
            $this->addError($attribute, "The {$this->operator->value} operator expects at least one value.");
            return;
        }

        // A range has a bottom and a top. Anything else is a mistake rather than a bound to guess at.
        if ($this->operator->expectsRange() && count((array)$this->value) !== 2) {
            $this->addError($attribute, "The {$this->operator->value} operator expects a lowest and a highest value.");
            return;
        }

        // A range whose bottom is above its top matches nothing at all, which no provider would
        // report as a mistake — so it is one here, before anything runs.
        if ($this->operator->expectsRange() && $this->isInverted()) {
            $this->addError($attribute, "The {$this->operator->value} operator expects its lowest value first.");
            return;
        }

        // Anything a provider cannot compare against is rejected here rather than translated.
        foreach (is_array($this->value) ? $this->value : [$this->value] as $value) {
            if (!is_scalar($value) && !$value instanceof DateTimeInterface) {
                $this->addError($attribute, 'A filter value must be a string, number, boolean or date.');
                return;
            }

            if ($this->operator->isComparison() && !is_int($value) && !is_float($value) && !is_string($value) && !$value instanceof DateTimeInterface) {
                $this->addError($attribute, "The {$this->operator->value} operator expects a number, string or date.");
                return;
            }
        }
    }
}
