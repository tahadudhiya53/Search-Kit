<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use craft\base\ElementInterface;
use craft\fields\PlainText;
use RuntimeException;

/**
 * A custom field whose keyword extraction blows up.
 */
class ThrowingField extends PlainText
{
    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        throw new RuntimeException('Custom field keyword extraction exploded.');
    }
}
