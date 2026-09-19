<?php

namespace Tahadudhiya\SearchKit\records;

use craft\db\ActiveRecord;
use Tahadudhiya\SearchKit\db\Table;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $provider
 * @property bool $enabled
 * @property string|null $settings
 * @property string|null $searchSettings
 * @property string|null $analyticsSettings
 * @property int|null $siteId
 * @property string|null $dateLastIndexed
 * @property int $configurationVersion
 * @property bool $rebuildRequired
 * @property bool $rebuildPending
 */
class SearchIndexRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::INDEXES;
    }
}
