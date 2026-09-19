<?php

namespace Tahadudhiya\SearchKit\services;

use craft\helpers\Json;
use Tahadudhiya\SearchKit\models\DashboardLayout;
use Tahadudhiya\SearchKit\records\DashboardLayoutRecord;
use yii\base\Component;

/**
 * How each person arranged their dashboard. It is a preference of theirs alone: nobody else's
 * dashboard moves, and nothing here decides what anyone is allowed to see.
 */
class DashboardLayouts extends Component
{
    public function getForUser(int $userId): DashboardLayout
    {
        $record = DashboardLayoutRecord::findOne(['userId' => $userId]);

        return DashboardLayout::fromConfig($record?->layout);
    }

    public function save(int $userId, DashboardLayout $layout): bool
    {
        $record = DashboardLayoutRecord::findOne(['userId' => $userId]) ?? new DashboardLayoutRecord(['userId' => $userId]);
        $record->layout = Json::encode($layout->toConfig());

        return $record->save();
    }

    /**
     * Forgets an arrangement, which puts that person's dashboard back the way it started.
     */
    public function reset(int $userId): bool
    {
        return DashboardLayoutRecord::deleteAll(['userId' => $userId]) > 0;
    }
}
