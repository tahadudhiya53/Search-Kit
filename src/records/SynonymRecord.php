<?php

namespace Tahadudhiya\SearchKit\records;

use craft\db\ActiveRecord;
use Tahadudhiya\SearchKit\db\Table;

/**
 * @property int $id
 * @property int|null $indexId
 * @property int|null $siteId
 * @property string $type
 * @property string $terms
 * @property string|null $replacements
 * @property bool $enabled
 * @property int $sortOrder
 */
class SynonymRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SYNONYMS;
    }
}
