<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use Tahadudhiya\SearchKit\enums\SortDirection;

/**
 * A provider-independent sort directive. The reserved field `score` means provider relevance.
 */
class SearchSort extends Model
{
    public const FIELD_SCORE = 'score';

    public string $field = self::FIELD_SCORE;
    public SortDirection $direction = SortDirection::Desc;

    public static function make(string $field, SortDirection $direction = SortDirection::Desc): self
    {
        return new self([
            'field' => $field,
            'direction' => $direction,
        ]);
    }

    public function isScore(): bool
    {
        return $this->field === self::FIELD_SCORE;
    }

    protected function defineRules(): array
    {
        return [
            [['field'], 'required'],
            [['field'], 'string', 'max' => 255],
        ];
    }
}
