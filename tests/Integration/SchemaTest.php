<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use Tahadudhiya\SearchKit\db\Table;

/**
 * Proves the install migration produced the schema SearchKit relies on.
 */
class SchemaTest extends IntegrationTestCase
{
    public function testEveryTableExists(): void
    {
        self::assertNotEmpty($this->tableSchema(Table::INDEXES)->columns);
        self::assertNotEmpty($this->tableSchema(Table::SEARCHABLEFIELDS)->columns);
        self::assertNotEmpty($this->tableSchema(Table::INDEXOPERATIONS)->columns);
        self::assertNotEmpty($this->tableSchema(Table::SYNONYMS)->columns);
        self::assertNotEmpty($this->tableSchema(Table::TERMS)->columns);
        self::assertNotEmpty($this->tableSchema(Table::RULES)->columns);
        self::assertNotEmpty($this->tableSchema(Table::RULEACTIONS)->columns);
        self::assertNotEmpty($this->tableSchema(Table::SEARCHEVENTS)->columns);
        self::assertNotEmpty($this->tableSchema(Table::SEARCHCLICKS)->columns);
        self::assertNotEmpty($this->tableSchema(Table::DASHBOARDLAYOUTS)->columns);
    }

    public function testRuleTablesHaveTheExpectedColumns(): void
    {
        $rules = $this->tableSchema(Table::RULES);

        self::assertEqualsCanonicalizing(
            [
                'id', 'indexId', 'siteId', 'name', 'enabled', 'priority', 'matchType', 'matchValue',
                'dateStart', 'dateEnd', 'dateCreated', 'dateUpdated', 'uid',
            ],
            array_keys($rules->columns),
        );

        // A rule always governs one index; a null site is what makes it apply in every one of them.
        self::assertFalse($rules->columns['indexId']->allowNull);
        self::assertTrue($rules->columns['siteId']->allowNull);
        self::assertTrue($rules->columns['dateStart']->allowNull);
        self::assertTrue($rules->columns['dateEnd']->allowNull);

        $actions = $this->tableSchema(Table::RULEACTIONS);

        self::assertEqualsCanonicalizing(
            [
                'id', 'ruleId', 'type', 'elementId', 'elementType', 'siteId', 'amount', 'position',
                'value', 'sortOrder', 'dateCreated', 'dateUpdated', 'uid',
            ],
            array_keys($actions->columns),
        );

        // One table for every kind of control, so a redirect carries no element and a pin no value.
        self::assertFalse($actions->columns['ruleId']->allowNull);
        self::assertTrue($actions->columns['elementId']->allowNull);
        self::assertTrue($actions->columns['position']->allowNull);
        // Null means every site the rule covers; a placed result always names one.
        self::assertTrue($actions->columns['siteId']->allowNull);
        self::assertTrue($actions->columns['value']->allowNull);
    }

    public function testIndexesTableHasTheExpectedColumns(): void
    {
        $schema = $this->tableSchema(Table::INDEXES);

        self::assertSame(['id'], $schema->primaryKey);
        self::assertEqualsCanonicalizing(
            [
                'id', 'name', 'handle', 'provider', 'enabled', 'settings', 'searchSettings',
                'analyticsSettings', 'siteId', 'dateLastIndexed', 'configurationVersion',
                'rebuildRequired', 'rebuildPending', 'dateCreated', 'dateUpdated', 'uid',
            ],
            array_keys($schema->columns),
        );

        self::assertFalse($schema->columns['handle']->allowNull);
        self::assertFalse($schema->columns['provider']->allowNull);
        self::assertTrue($schema->columns['siteId']->allowNull);
        self::assertTrue($schema->columns['settings']->allowNull);
        self::assertTrue($schema->columns['searchSettings']->allowNull);
        self::assertTrue($schema->columns['analyticsSettings']->allowNull);
        self::assertTrue($schema->columns['dateLastIndexed']->allowNull);
        self::assertFalse($schema->columns['rebuildRequired']->allowNull);
        self::assertFalse($schema->columns['rebuildPending']->allowNull);
        // The generation is what stops an old rebuild settling a newer configuration.
        self::assertFalse($schema->columns['configurationVersion']->allowNull);
    }

    public function testSearchActivityTablesHaveTheExpectedColumns(): void
    {
        $events = $this->tableSchema(Table::SEARCHEVENTS);

        self::assertEqualsCanonicalizing(
            [
                'id', 'indexId', 'siteId', 'query', 'normalizedQuery', 'correctedQuery', 'language',
                'resultCount', 'trackedResults', 'executionTime', 'clickCount', 'dateCreated', 'uid',
            ],
            array_keys($events->columns),
        );

        // A search of an index's whole scope names no site; one of a single site names it.
        self::assertTrue($events->columns['siteId']->allowNull);
        self::assertFalse($events->columns['query']->allowNull);
        self::assertFalse($events->columns['resultCount']->allowNull);
        self::assertTrue($events->columns['correctedQuery']->allowNull);
        // A search nobody could click on remembers no results, so a click has nothing to name.
        self::assertTrue($events->columns['trackedResults']->allowNull);

        $clicks = $this->tableSchema(Table::SEARCHCLICKS);

        self::assertEqualsCanonicalizing(
            ['id', 'eventId', 'elementId', 'elementType', 'siteId', 'position', 'dateCreated'],
            array_keys($clicks->columns),
        );

        self::assertFalse($clicks->columns['eventId']->allowNull);
        self::assertFalse($clicks->columns['elementId']->allowNull);
        self::assertFalse($clicks->columns['siteId']->allowNull);
    }

    public function testIndexOperationsTableHasTheExpectedColumns(): void
    {
        $schema = $this->tableSchema(Table::INDEXOPERATIONS);

        self::assertSame(['id'], $schema->primaryKey);
        self::assertEqualsCanonicalizing(
            [
                'id', 'indexId', 'elementId', 'siteId', 'elementType', 'operation',
                'status', 'token', 'attempts', 'error', 'dateCreated', 'dateUpdated', 'uid',
            ],
            array_keys($schema->columns),
        );

        self::assertFalse($schema->columns['indexId']->allowNull);
        self::assertFalse($schema->columns['elementId']->allowNull);
        self::assertFalse($schema->columns['siteId']->allowNull);
        self::assertFalse($schema->columns['status']->allowNull);
        self::assertTrue($schema->columns['error']->allowNull);
        // The concurrency token is what stops an older worker releasing a newer intent.
        self::assertFalse($schema->columns['token']->allowNull);
    }

    public function testSearchableFieldsTableHasTheExpectedColumns(): void
    {
        $schema = $this->tableSchema(Table::SEARCHABLEFIELDS);

        self::assertSame(['id'], $schema->primaryKey);
        self::assertEqualsCanonicalizing(
            ['id', 'indexId', 'elementType', 'handle', 'weight', 'enabled', 'dateCreated', 'dateUpdated', 'uid'],
            array_keys($schema->columns),
        );

        self::assertFalse($schema->columns['indexId']->allowNull);
        self::assertFalse($schema->columns['weight']->allowNull);
    }

    public function testSynonymsTableHasTheExpectedColumns(): void
    {
        $schema = $this->tableSchema(Table::SYNONYMS);

        self::assertSame(['id'], $schema->primaryKey);
        self::assertEqualsCanonicalizing(
            [
                'id', 'indexId', 'siteId', 'type', 'terms', 'replacements', 'enabled', 'sortOrder',
                'dateCreated', 'dateUpdated', 'uid',
            ],
            array_keys($schema->columns),
        );

        // A null index or site is what makes a group govern every one of them.
        self::assertTrue($schema->columns['indexId']->allowNull);
        self::assertTrue($schema->columns['siteId']->allowNull);
        self::assertFalse($schema->columns['terms']->allowNull);
        self::assertTrue($schema->columns['replacements']->allowNull);
    }

    public function testTermsTableHasTheExpectedColumns(): void
    {
        $schema = $this->tableSchema(Table::TERMS);

        self::assertSame(['id'], $schema->primaryKey);
        self::assertEqualsCanonicalizing(
            ['id', 'indexId', 'elementId', 'siteId', 'elementType', 'term', 'dateCreated'],
            array_keys($schema->columns),
        );

        self::assertFalse($schema->columns['indexId']->allowNull);
        self::assertFalse($schema->columns['elementId']->allowNull);
        self::assertFalse($schema->columns['siteId']->allowNull);
        self::assertFalse($schema->columns['elementType']->allowNull);
        self::assertFalse($schema->columns['term']->allowNull);
    }

    public function testHandleIsUniqueAndFieldsAreUniquePerIndexAndElementType(): void
    {
        self::assertContains(['handle'], $this->uniqueIndexes(Table::INDEXES));
        self::assertContains(
            ['indexId', 'elementType', 'handle'],
            array_map(static fn(array $columns) => array_values($columns), $this->uniqueIndexes(Table::SEARCHABLEFIELDS)),
        );

        // One outstanding operation per element per site, so a save storm cannot pile up rows.
        self::assertContains(
            ['indexId', 'elementId', 'siteId'],
            array_map(static fn(array $columns) => array_values($columns), $this->uniqueIndexes(Table::INDEXOPERATIONS)),
        );

        // One row per word per document, which is what lets a word outlive one document but not all.
        self::assertContains(
            ['indexId', 'elementId', 'siteId', 'term'],
            array_map(static fn(array $columns) => array_values($columns), $this->uniqueIndexes(Table::TERMS)),
        );
    }

    public function testForeignKeysPointWhereTheyShould(): void
    {
        self::assertSame(
            ['sites', 'siteId' => 'id'],
            $this->foreignKeyFor(Table::INDEXES, 'siteId'),
        );

        self::assertSame(
            [Craft::$app->getDb()->getSchema()->getRawTableName(Table::INDEXES), 'indexId' => 'id'],
            $this->foreignKeyFor(Table::SEARCHABLEFIELDS, 'indexId'),
        );

        self::assertSame(
            [Craft::$app->getDb()->getSchema()->getRawTableName(Table::INDEXES), 'indexId' => 'id'],
            $this->foreignKeyFor(Table::INDEXOPERATIONS, 'indexId'),
        );

        self::assertSame(
            [Craft::$app->getDb()->getSchema()->getRawTableName(Table::INDEXES), 'indexId' => 'id'],
            $this->foreignKeyFor(Table::SYNONYMS, 'indexId'),
        );

        self::assertSame(
            [Craft::$app->getDb()->getSchema()->getRawTableName(Table::INDEXES), 'indexId' => 'id'],
            $this->foreignKeyFor(Table::TERMS, 'indexId'),
        );

        // A word cannot outlive the element it was read from, however the element goes away.
        self::assertSame(
            ['elements', 'elementId' => 'id'],
            $this->foreignKeyFor(Table::TERMS, 'elementId'),
        );

        self::assertSame(
            [Craft::$app->getDb()->getSchema()->getRawTableName(Table::INDEXES), 'indexId' => 'id'],
            $this->foreignKeyFor(Table::RULES, 'indexId'),
        );

        self::assertSame(
            [Craft::$app->getDb()->getSchema()->getRawTableName(Table::RULES), 'ruleId' => 'id'],
            $this->foreignKeyFor(Table::RULEACTIONS, 'ruleId'),
        );

        // An action naming a deleted element has nothing left to do, so it goes with it.
        self::assertSame(
            ['elements', 'elementId' => 'id'],
            $this->foreignKeyFor(Table::RULEACTIONS, 'elementId'),
        );

        // Deliberately none: a deletion operation has to outlive the element it removes.
        self::assertNull($this->foreignKeyFor(Table::INDEXOPERATIONS, 'elementId'));
    }

    public function testInstalledSchemaVersionMatchesThePlugin(): void
    {
        $stored = Craft::$app->getPlugins()->getStoredPluginInfo('search-kit')['schemaVersion'] ?? null;

        self::assertSame($this->plugin()->schemaVersion, $stored);
    }

    private function tableSchema(string $table): \yii\db\TableSchema
    {
        $schema = Craft::$app->getDb()->getTableSchema($table, true);
        self::assertNotNull($schema, "Table $table is missing.");

        return $schema;
    }

    /**
     * @return array<int,array<int,string>>
     */
    private function uniqueIndexes(string $table): array
    {
        $db = Craft::$app->getDb();

        return array_values(array_map(
            static fn(array $columns) => array_values($columns),
            $db->getSchema()->findUniqueIndexes($this->tableSchema($table)),
        ));
    }

    /**
     * @return array<mixed>|null
     */
    private function foreignKeyFor(string $table, string $column): ?array
    {
        foreach ($this->tableSchema($table)->foreignKeys as $foreignKey) {
            if (array_key_exists($column, $foreignKey)) {
                return $foreignKey;
            }
        }

        return null;
    }
}
