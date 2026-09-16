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
    }

    public function testIndexesTableHasTheExpectedColumns(): void
    {
        $schema = $this->tableSchema(Table::INDEXES);

        self::assertSame(['id'], $schema->primaryKey);
        self::assertEqualsCanonicalizing(
            [
                'id', 'name', 'handle', 'provider', 'enabled', 'settings', 'siteId',
                'dateLastIndexed', 'configurationVersion', 'rebuildRequired', 'rebuildPending',
                'dateCreated', 'dateUpdated', 'uid',
            ],
            array_keys($schema->columns),
        );

        self::assertFalse($schema->columns['handle']->allowNull);
        self::assertFalse($schema->columns['provider']->allowNull);
        self::assertTrue($schema->columns['siteId']->allowNull);
        self::assertTrue($schema->columns['settings']->allowNull);
        self::assertTrue($schema->columns['dateLastIndexed']->allowNull);
        self::assertFalse($schema->columns['rebuildRequired']->allowNull);
        self::assertFalse($schema->columns['rebuildPending']->allowNull);
        // The generation is what stops an old rebuild settling a newer configuration.
        self::assertFalse($schema->columns['configurationVersion']->allowNull);
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
