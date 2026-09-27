<?php

namespace Tahadudhiya\SearchKit\records;

use craft\db\ActiveRecord;
use Tahadudhiya\SearchKit\db\Table;

/**
 * @property int $id
 * @property string $name
 * @property string $hash
 * @property string $prefix
 * @property bool $enabled
 * @property string|null $indexIds
 * @property int|null $rateLimit
 * @property string|null $dateLastUsed
 */
class ApiKeyRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::APIKEYS;
    }
}
