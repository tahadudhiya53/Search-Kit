<?php

namespace Tahadudhiya\SearchKit\records;

use craft\db\ActiveRecord;
use Tahadudhiya\SearchKit\db\Table;

/**
 * @property int $id
 * @property int $indexId
 * @property string $elementType
 * @property string $handle
 * @property int $weight
 * @property bool $enabled
 */
class SearchableFieldRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SEARCHABLEFIELDS;
    }
}
