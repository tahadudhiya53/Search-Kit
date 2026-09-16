<?php

namespace Tahadudhiya\SearchKit\records;

use craft\db\ActiveRecord;
use Tahadudhiya\SearchKit\db\Table;

/**
 * @property int $id
 * @property int $indexId
 * @property int $elementId
 * @property int $siteId
 * @property string $elementType
 * @property string $operation
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 */
class IndexOperationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::INDEXOPERATIONS;
    }
}
