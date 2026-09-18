<?php

namespace Tahadudhiya\SearchKit\records;

use craft\db\ActiveRecord;
use Tahadudhiya\SearchKit\db\Table;

/**
 * @property int $id
 * @property int $ruleId
 * @property string $type
 * @property int|null $elementId
 * @property string|null $elementType
 * @property int|null $siteId
 * @property float $amount
 * @property int|null $position
 * @property string|null $value
 * @property int $sortOrder
 */
class RuleActionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::RULEACTIONS;
    }
}
