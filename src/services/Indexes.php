<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\records\SearchIndexRecord;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\db\Expression;

/**
 * Reads and writes the administrator-managed search indexes.
 */
class Indexes extends Component
{
    /** @var SearchIndex[]|null */
    private ?array $_indexes = null;

    private ?SearchableFields $_searchableFields = null;

    /**
     * @return SearchIndex[]
     */
    public function getAllIndexes(): array
    {
        if ($this->_indexes === null) {
            $rows = (new Query())
                ->select(['id', 'name', 'handle', 'provider', 'enabled', 'settings', 'siteId', 'dateLastIndexed', 'configurationVersion',
                    'rebuildRequired', 'rebuildPending', 'uid', ])
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

    /**
     * Saves an index and the fields it searches as one thing. A configuration that is half applied
     * would leave the provider holding content no configuration asked for, so either all of it
     * lands or none of it does.
     *
     * @param SearchableField[] $fields
     */
    public function saveIndexConfiguration(SearchIndex $index, array $fields, bool $runValidation = true): bool
    {
        $wasNew = $index->id === null;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // The index goes first because the fields need its ID; a rollback undoes both, so a
            // field that fails validation still leaves the previous configuration intact.
            if (!$this->saveIndex($index, $runValidation)) {
                $transaction->rollBack();
                $this->forget($index, $wasNew);

                return false;
            }

            if (!$this->getSearchableFields()->saveFieldsForIndex($index, $fields, $runValidation)) {
                foreach ($fields as $field) {
                    foreach ($field->getErrorSummary(true) as $error) {
                        $index->addError('fields', $error);
                    }
                }

                if (!$index->hasErrors('fields')) {
                    $index->addError('fields', 'The searchable fields could not be saved.');
                }

                $transaction->rollBack();
                $this->forget($index, $wasNew);

                return false;
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            $this->forget($index, $wasNew);

            throw $e;
        }

        $this->_indexes = null;

        return true;
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

        // What the provider already holds can only be trusted while these stay put. Re-enabling
        // counts: nothing was tracked while the index was off.
        $storedSiteId = $record->siteId !== null ? (int)$record->siteId : null;
        $invalidated = $index->id !== null && (
            $record->provider !== $index->provider
            || $storedSiteId !== $index->siteId
            || Json::encode($index->settings) !== ($record->settings ?? Json::encode([]))
            || (!$record->enabled && $index->enabled)
        );

        $record->name = $index->name;
        $record->handle = $index->handle;
        $record->provider = $index->provider;
        $record->enabled = $index->enabled;
        $record->settings = $index->settings !== [] ? Json::encode($index->settings) : null;
        $record->siteId = $index->siteId;
        $record->rebuildRequired = $index->rebuildRequired || $invalidated;

        // A configuration change means the last rebuild no longer covers this configuration at all,
        // and starts a new generation in the same write, so a rollback takes it with them.
        $record->rebuildPending = $invalidated ? false : $index->rebuildPending;

        if ($invalidated) {
            $record->configurationVersion = (int)$record->configurationVersion + 1;
        }

        if (!$record->save()) {
            $index->addErrors($record->getErrors());
            return false;
        }

        $index->id = $record->id;
        $index->uid = $record->uid;
        $index->rebuildRequired = (bool)$record->rebuildRequired;
        $index->rebuildPending = (bool)$record->rebuildPending;
        $index->configurationVersion = (int)$record->configurationVersion;
        $this->_indexes = null;

        return true;
    }

    /**
     * Puts the model back where a rolled-back save left the database, so a failed save never hands
     * back an index that looks saved.
     */
    private function forget(SearchIndex $index, bool $wasNew): void
    {
        if ($wasNew) {
            $index->id = null;
            $index->uid = null;
        }

        $this->_indexes = null;
    }

    public function setSearchableFields(SearchableFields $searchableFields): void
    {
        $this->_searchableFields = $searchableFields;
    }

    public function getSearchableFields(): SearchableFields
    {
        return $this->_searchableFields ??= SearchKit::getInstance()?->getSearchableFields()
            ?? throw new InvalidConfigException('SearchKit is not installed or is disabled.');
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

    /**
     * Stamps an index as having just finished a run with nothing left outstanding, without going
     * through validation or touching the administrator-managed columns.
     */
    public function updateLastIndexed(SearchIndex $index, ?DateTime $date = null): void
    {
        if ($index->id === null) {
            return;
        }

        $date ??= DateTimeHelper::now();

        Db::update(Table::INDEXES, ['dateLastIndexed' => Db::prepareDateForDb($date)], ['id' => $index->id]);

        $index->dateLastIndexed = $date;
        $this->_indexes = null;
    }

    /**
     * The index is current: a rebuild has covered this configuration and nothing was left behind.
     *
     * @param int $configurationVersion The generation the rebuild started against.
     * @return bool False when the configuration moved on, so this rebuild settled nothing.
     */
    public function markRebuildComplete(SearchIndex $index, int $configurationVersion, ?DateTime $date = null): bool
    {
        if ($index->id === null) {
            return false;
        }

        $date ??= DateTimeHelper::now();

        $applied = $this->updateGeneration($index->id, $configurationVersion, [
            'dateLastIndexed' => Db::prepareDateForDb($date),
            'rebuildRequired' => false,
            'rebuildPending' => false,
        ]);

        if ($applied) {
            $index->dateLastIndexed = $date;
            $index->rebuildRequired = false;
            $index->rebuildPending = false;
        }

        return $applied;
    }

    /**
     * A rebuild walked this configuration but left work behind. The index still owes a rebuild
     * until that work succeeds, and the last-indexed stamp is left alone rather than claiming it.
     *
     * @param int $configurationVersion The generation the rebuild started against.
     * @return bool False when the configuration moved on, so this rebuild settled nothing.
     */
    public function markRebuildIncomplete(SearchIndex $index, int $configurationVersion): bool
    {
        if ($index->id === null) {
            return false;
        }

        $applied = $this->updateGeneration($index->id, $configurationVersion, [
            'rebuildRequired' => true,
            'rebuildPending' => true,
        ]);

        if ($applied) {
            $index->rebuildRequired = true;
            $index->rebuildPending = true;
        }

        return $applied;
    }

    /**
     * Records that the configuration changed, so whatever the provider holds may no longer match it.
     * Every change starts a new generation, even one on an index that already owed a rebuild.
     */
    public function markRebuildRequired(SearchIndex $index): void
    {
        if ($index->id === null) {
            return;
        }

        Db::update(Table::INDEXES, [
            'configurationVersion' => new Expression('[[configurationVersion]] + 1'),
            'rebuildRequired' => true,
            'rebuildPending' => false,
        ], ['id' => $index->id]);

        $index->configurationVersion = $this->configurationVersionOf($index->id) ?? $index->configurationVersion + 1;
        $index->rebuildRequired = true;
        $index->rebuildPending = false;
        $this->_indexes = null;
    }

    /**
     * Writes rebuild state only while the index is still on the generation the caller rebuilt.
     *
     * @param array<string,mixed> $columns
     */
    private function updateGeneration(int $id, int $configurationVersion, array $columns): bool
    {
        $condition = ['id' => $id, 'configurationVersion' => $configurationVersion];
        $applied = Db::update(Table::INDEXES, $columns, $condition) > 0;

        // An update that changed nothing reports no rows, so the generation itself settles whether
        // the row was ours: the write above already refused to touch any other generation.
        if (!$applied) {
            $applied = $this->configurationVersionOf($id) === $configurationVersion;
        }

        $this->_indexes = null;

        return $applied;
    }

    private function configurationVersionOf(int $id): ?int
    {
        $version = (new Query())
            ->select(['configurationVersion'])
            ->from([Table::INDEXES])
            ->where(['id' => $id])
            ->scalar();

        return $version !== false && $version !== null ? (int)$version : null;
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
            'dateLastIndexed' => DateTimeHelper::toDateTime($row['dateLastIndexed']) ?: null,
            'configurationVersion' => (int)$row['configurationVersion'],
            'rebuildRequired' => (bool)$row['rebuildRequired'],
            'rebuildPending' => (bool)$row['rebuildPending'],
            'uid' => (string)$row['uid'],
        ]);
    }
}
