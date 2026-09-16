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
