<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use craft\base\FieldInterface;
use craft\models\FieldLayout;

/**
 * A layout that answers every handle with a field that cannot be read.
 */
class ThrowingFieldLayout extends FieldLayout
{
    public function getFieldByHandle(string $handle): ?FieldInterface
    {
        return new ThrowingField(['handle' => $handle]);
    }
}
