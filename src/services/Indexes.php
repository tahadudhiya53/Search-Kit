<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\db\Query;
use craft\helpers\Json;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\records\SearchIndexRecord;
use yii\base\Component;

/**
 * Reads and writes the administrator-managed search indexes.
 */
class Indexes extends Component
{
    /** @var SearchIndex[]|null */
    private ?array $_indexes = null;

    /**
     * @return SearchIndex[]
     */
    public function getAllIndexes(): array
    {
        if ($this->_indexes === null) {
            $rows = (new Query())
                ->select(['id', 'name', 'handle', 'provider', 'enabled', 'settings', 'siteId', 'uid'])
                ->from([Table::INDEXES])
                ->orderBy(['name' => SORT_ASC])
                ->all();

            $this->_indexes = array_map(fn(array $row) => $this->createIndexFromRow($row), $rows);
        }

        return $this->_indexes;
    }

    public function getIndexById(int $id): ?SearchIndex
    {
        foreach ($this->getAllIndexes() as $index) {
            if ($index->id === $id) {
                return $index;
            }
        }

        return null;
    }

    public function getIndexByHandle(string $handle): ?SearchIndex
    {
        foreach ($this->getAllIndexes() as $index) {
            if ($index->handle === $handle) {
                return $index;
            }
        }

        return null;
    }

    public function saveIndex(SearchIndex $index, bool $runValidation = true): bool
    {
        if ($runValidation && !$index->validate()) {
            return false;
        }

        $record = $index->id !== null
            ? SearchIndexRecord::findOne($index->id)
            : new SearchIndexRecord();

        if ($record === null) {
            $index->addError('id', "No search index exists with the ID “{$index->id}”.");
            return false;
        }

        if ($this->handleIsTaken($index)) {
            $index->addError('handle', "The handle “{$index->handle}” is already in use.");
            return false;
        }

        // Caught here rather than left to the foreign key, so callers get a validation error.
        if ($index->siteId !== null && Craft::$app->getSites()->getSiteById($index->siteId) === null) {
            $index->addError('siteId', "No site exists with the ID “{$index->siteId}”.");
            return false;
        }

        $record->name = $index->name;
        $record->handle = $index->handle;
        $record->provider = $index->provider;
        $record->enabled = $index->enabled;
        $record->settings = $index->settings !== [] ? Json::encode($index->settings) : null;
        $record->siteId = $index->siteId;

        if (!$record->save()) {
            $index->addErrors($record->getErrors());
            return false;
        }

        $index->id = $record->id;
        $index->uid = $record->uid;
        $this->_indexes = null;

        return true;
    }

    public function deleteIndex(SearchIndex $index): bool
    {
        if ($index->id === null) {
            return false;
        }

        $record = SearchIndexRecord::findOne($index->id);

        if ($record === null) {
            return false;
        }

        // Searchable fields are removed by the table's cascading foreign key.
        $deleted = (bool)$record->delete();
        $this->_indexes = null;

        return $deleted;
    }

    private function handleIsTaken(SearchIndex $index): bool
    {
        $query = SearchIndexRecord::find()->where(['handle' => $index->handle]);

        if ($index->id !== null) {
            $query->andWhere(['not', ['id' => $index->id]]);
        }

        return $query->exists();
    }

    /**
     * @param array<string,mixed> $row
     */
    private function createIndexFromRow(array $row): SearchIndex
    {
        return new SearchIndex([
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'handle' => (string)$row['handle'],
            'provider' => (string)$row['provider'],
            'enabled' => (bool)$row['enabled'],
            'settings' => $row['settings'] !== null ? Json::decode($row['settings']) : [],
            'siteId' => $row['siteId'] !== null ? (int)$row['siteId'] : null,
            'uid' => (string)$row['uid'],
        ]);
    }
}
