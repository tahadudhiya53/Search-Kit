<?php

namespace Tahadudhiya\SearchKit\services;

use craft\db\Query;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\records\SearchableFieldRecord;
use yii\base\Component;

/**
 * Reads and writes which fields an index searches, and how heavily each one counts.
 */
class SearchableFields extends Component
{
    /**
     * @return SearchableField[]
     */
    public function getFieldsByIndexId(int $indexId): array
    {
        $rows = (new Query())
            ->select(['id', 'indexId', 'elementType', 'handle', 'weight', 'enabled', 'uid'])
            ->from([Table::SEARCHABLEFIELDS])
            ->where(['indexId' => $indexId])
            ->orderBy(['weight' => SORT_DESC, 'handle' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => $this->createFieldFromRow($row), $rows);
    }

    /**
     * Loads an index's fields onto it, unless something already has.
     */
    public function attachFields(SearchIndex $index): void
    {
        if (!$index->fieldsAreLoaded() && $index->id !== null) {
            $index->setFields($this->getFieldsByIndexId($index->id));
        }
    }

    public function saveField(SearchableField $field, bool $runValidation = true): bool
    {
        if ($runValidation && !$field->validate()) {
            return false;
        }

        // Caught here rather than left to the foreign key, so callers get a validation error.
        if (!$this->indexExists($field->indexId)) {
            $field->addError('indexId', "No search index exists with the ID “{$field->indexId}”.");
            return false;
        }

        $record = $field->id !== null
            ? SearchableFieldRecord::findOne($field->id)
            : new SearchableFieldRecord();

        if ($record === null) {
            $field->addError('id', "No searchable field exists with the ID “{$field->id}”.");
            return false;
        }

        $record->indexId = $field->indexId;
        $record->elementType = $field->elementType;
        $record->handle = $field->handle;
        $record->weight = $field->weight;
        $record->enabled = $field->enabled;

        if (!$record->save()) {
            $field->addErrors($record->getErrors());
            return false;
        }

        $field->id = $record->id;
        $field->uid = $record->uid;

        return true;
    }

    public function deleteField(SearchableField $field): bool
    {
        if ($field->id === null) {
            return false;
        }

        $record = SearchableFieldRecord::findOne($field->id);

        return $record !== null && (bool)$record->delete();
    }

    private function indexExists(?int $indexId): bool
    {
        return $indexId !== null && (new Query())
            ->from([Table::INDEXES])
            ->where(['id' => $indexId])
            ->exists();
    }

    /**
     * @param array<string,mixed> $row
     */
    private function createFieldFromRow(array $row): SearchableField
    {
        return new SearchableField([
            'id' => (int)$row['id'],
            'indexId' => (int)$row['indexId'],
            'elementType' => (string)$row['elementType'],
            'handle' => (string)$row['handle'],
            'weight' => (int)$row['weight'],
            'enabled' => (bool)$row['enabled'],
            'uid' => (string)$row['uid'],
        ]);
    }
}
