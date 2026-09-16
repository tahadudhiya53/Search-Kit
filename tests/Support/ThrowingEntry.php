<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use craft\elements\Entry;
use craft\models\FieldLayout;
use RuntimeException;

/**
 * An entry whose values cannot be read, to prove an extraction failure is a failure rather than a
 * silently empty document. Set $layout to exercise the custom field path instead of attributes.
 */
class ThrowingEntry extends Entry
{
    public ?FieldLayout $layout = null;

    public function getFieldLayout(): ?FieldLayout
    {
        return $this->layout;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        return '';
    }

    public function getSearchKeywords(string $attribute): string
    {
        throw new RuntimeException('Attribute keyword extraction exploded.');
    }
}
