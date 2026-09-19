<?php

namespace Tahadudhiya\SearchKit\records;

use craft\db\ActiveRecord;
use Tahadudhiya\SearchKit\db\Table;

/**
 * @property int $id
 * @property int $indexId
 * @property int|null $siteId
 * @property string $name
 * @property bool $enabled
 * @property int $priority
 * @property string $matchType
 * @property string $matchValue
 * @property string|null $dateStart
 * @property string|null $dateEnd
 */
class SearchRuleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::RULES;
    }
}
