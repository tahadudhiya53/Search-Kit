<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use Tahadudhiya\SearchKit\services\Terms;
use yii\db\Exception as DbException;

/**
 * A dictionary that cannot be written to, to prove indexing is never settled on a document the
 * words do not describe.
 */
class FailingTerms extends Terms
{
    public static bool $failing = true;

    public function record(int $indexId, int $elementId, int $siteId, string $elementType, array $terms): void
    {
        if (self::$failing) {
            throw new DbException('The words could not be written.');
        }

        parent::record($indexId, $elementId, $siteId, $elementType, $terms);
    }
}
