<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\ElementInterface;
use craft\base\Model;
use craft\validators\HandleValidator;
use DateTime;

/**
 * One searchable element attribute or custom field on an index, and how heavily it counts.
 */
class SearchableField extends Model
{
    public const DEFAULT_WEIGHT = 1;
    public const MAX_WEIGHT = 1000;

    public ?int $id = null;
    public ?int $indexId = null;

    /** @var string The element class this field belongs to. */
    public string $elementType = '';

    /** @var string An element attribute handle (`title`) or a custom field handle. */
    public string $handle = '';

    public int $weight = self::DEFAULT_WEIGHT;
    public bool $enabled = true;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    protected function defineRules(): array
    {
        return [
            [['indexId', 'elementType', 'handle'], 'required'],
            [['elementType', 'handle'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class],
            [['elementType'], 'validateElementType'],
            [['weight'], 'integer', 'min' => 0, 'max' => self::MAX_WEIGHT],
            [['indexId', 'id'], 'integer', 'min' => 1],
        ];
    }

    public function validateElementType(string $attribute): void
    {
        if (!is_subclass_of($this->elementType, ElementInterface::class)) {
            $this->addError($attribute, "“{$this->elementType}” is not an element type.");
        }
    }
}
