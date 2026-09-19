<?php

namespace Tahadudhiya\SearchKit\records;

use craft\db\ActiveRecord;
use Tahadudhiya\SearchKit\db\Table;

/**
 * @property int $id
 * @property int $userId
 * @property string|null $layout
 */
class DashboardLayoutRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DASHBOARDLAYOUTS;
    }
}
