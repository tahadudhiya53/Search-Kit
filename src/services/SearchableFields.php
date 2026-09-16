<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Db;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\records\SearchableFieldRecord;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Reads and writes which fields an index searches, and how heavily each one counts. It is also the
 * authority on what may be configured, so the control panel offers exactly what a save accepts.
 */
class SearchableFields extends Component
{
    public const EVENT_REGISTER_INDEXABLE_ELEMENT_TYPES = 'registerIndexableElementTypes';

    private ?Indexes $_indexes = null;

    /** @var array<string,string[]> Available handles per element type, for one request. */
    private array $_availableHandles = [];

    /**
     * The element types SearchKit indexes. Anything else is a deliberate extension, not a default.
     *
     * @return string[]
     */
    public function getIndexableElementTypes(): array
    {
        $event = new RegisterComponentTypesEvent([
            'types' => [Entry::class, Category::class, Asset::class, User::class],
        ]);

        $this->trigger(self::EVENT_REGISTER_INDEXABLE_ELEMENT_TYPES, $event);

        return $event->types;
    }

    /**
     * The handles an element type can be indexed on: its searchable attributes, plus the searchable
     * custom fields on any of its field layouts.
     *
     * @param class-string<ElementInterface> $elementType
     * @return string[]
     */
    public function getAvailableHandles(string $elementType): array
    {
        if (isset($this->_availableHandles[$elementType])) {
            return $this->_availableHandles[$elementType];
        }

        $handles = $elementType::searchableAttributes();
        $handles[] = 'slug';

        if ($elementType::hasTitles()) {
            $handles[] = 'title';
        }

        foreach (Craft::$app->getFields()->getLayoutsByType($elementType) as $layout) {
            foreach ($layout->getCustomFields() as $field) {
                if ($field->searchable) {
                    $handles[] = $field->handle;
                }
            }
        }

        $handles = array_values(array_unique($handles));
        sort($handles);

        return $this->_availableHandles[$elementType] = $handles;
    }

    /**
     * Checks a field set against what may actually be indexed, so nothing a caller posts is taken
     * on trust. Errors land on the fields themselves.
     *
     * @param SearchableField[] $fields
     */
    public function validateFields(array $fields): bool
    {
        $indexable = $this->getIndexableElementTypes();
        $seen = [];
        $valid = true;

        foreach ($fields as $field) {
            $valid = $field->validate() && $valid;

            if (!in_array($field->elementType, $indexable, true)) {
                $field->addError('elementType', "“{$field->elementType}” is not an indexable element type.");
                $valid = false;
                continue;
            }

            if (!in_array($field->handle, $this->getAvailableHandles($field->elementType), true)) {
                $field->addError('handle', "“{$field->handle}” is not a searchable field on this element type.");
                $valid = false;
            }

            $key = "{$field->elementType}.{$field->handle}";

            if (isset($seen[$key])) {
                $field->addError('handle', "“{$field->handle}” is configured more than once for this element type.");
                $valid = false;
            }

            $seen[$key] = true;
        }

        return $valid;
    }

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
        if ($runValidation && !$this->validateFields([$field])) {
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

    /**
     * Replaces an index's searchable fields with the given set, so an edited configuration is
     * never half-applied.
     *
     * @param SearchableField[] $fields
     */
    public function saveFieldsForIndex(SearchIndex $index, array $fields, bool $runValidation = true): bool
    {
        if ($index->id === null) {
            return false;
        }

        foreach ($fields as $field) {
            $field->id = null;
            $field->indexId = $index->id;
        }

        if ($runValidation && !$this->validateFields($fields)) {
            return false;
        }

        $before = $this->signature($this->getFieldsByIndexId($index->id));
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Rewritten wholesale: the rows carry no identity of their own, only configuration.
            Db::delete(Table::SEARCHABLEFIELDS, ['indexId' => $index->id]);

            foreach ($fields as $field) {
                if (!$this->saveField($field, false)) {
                    $transaction->rollBack();
                    return false;
                }
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $index->setFields($fields);

        // What the provider holds was built from the old fields, so a change makes it stale.
        if ($before !== $this->signature($fields)) {
            $this->getIndexes()->markRebuildRequired($index);
        }

        return true;
    }

    /**
     * Everything about a field set that decides what gets indexed, in a comparable form.
     *
     * @param SearchableField[] $fields
     */
    private function signature(array $fields): string
    {
        $parts = array_map(
            static fn(SearchableField $field) => implode(':', [
                $field->elementType,
                $field->handle,
                $field->weight,
                $field->enabled ? '1' : '0',
            ]),
            $fields,
        );

        sort($parts);

        return implode('|', $parts);
    }

    public function setIndexes(Indexes $indexes): void
    {
        $this->_indexes = $indexes;
    }

    public function getIndexes(): Indexes
    {
        return $this->_indexes ??= SearchKit::getInstance()?->getIndexes()
            ?? throw new InvalidConfigException('SearchKit is not installed or is disabled.');
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
