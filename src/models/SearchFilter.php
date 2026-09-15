<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
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
            [['value'], 'validateValue'],
        ];
    }

    public function validateValue(string $attribute): void
    {
        if ($this->operator->expectsArray() && !is_array($this->value)) {
            $this->addError($attribute, "The {$this->operator->value} operator expects an array of values.");
        } elseif (!$this->operator->expectsArray() && is_array($this->value)) {
            $this->addError($attribute, "The {$this->operator->value} operator expects a single value.");
        }
    }
}
