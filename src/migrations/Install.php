<?php

namespace Tahadudhiya\SearchKit\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\enums\IndexOperationStatus;

/**
 * Installs and uninstalls SearchKit's schema.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        // Dropped child-first so the foreign keys go with the tables.
        $this->dropTableIfExists(Table::INDEXOPERATIONS);
        $this->dropTableIfExists(Table::SEARCHABLEFIELDS);
        $this->dropTableIfExists(Table::INDEXES);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::INDEXES, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'provider' => $this->string()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'settings' => $this->text(),
            'siteId' => $this->integer(),
            'dateLastIndexed' => $this->dateTime(),
            // Bumped whenever an effective configuration change lands, so a rebuild that started
            // against an older one cannot report the newer one as indexed.
            'configurationVersion' => $this->integer()->notNull()->defaultValue(1),
            'rebuildRequired' => $this->boolean()->notNull()->defaultValue(false),
            // A rebuild walked this configuration but left work behind, so it is not current yet.
            'rebuildPending' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::SEARCHABLEFIELDS, [
            'id' => $this->primaryKey(),
            'indexId' => $this->integer()->notNull(),
            'elementType' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'weight' => $this->integer()->notNull()->defaultValue(1),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Outstanding indexing work. Rows are deleted once the provider has accepted them, so this
        // table only ever holds what is pending or has exhausted its retries.
        $this->createTable(Table::INDEXOPERATIONS, [
            'id' => $this->primaryKey(),
            'indexId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'elementType' => $this->string()->notNull(),
            'operation' => $this->string(16)->notNull(),
            // Changes whenever a newer intent replaces this row, so an older worker cannot release it.
            'token' => $this->char(36)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue(IndexOperationStatus::Pending->value),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'error' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::INDEXES, ['handle'], true);
        $this->createIndex(null, Table::INDEXES, ['siteId'], false);
        $this->createIndex(null, Table::SEARCHABLEFIELDS, ['indexId', 'elementType', 'handle'], true);

        // One outstanding operation per element per site per index: a newer one replaces it.
        $this->createIndex(null, Table::INDEXOPERATIONS, ['indexId', 'elementId', 'siteId'], true);
        $this->createIndex(null, Table::INDEXOPERATIONS, ['indexId', 'status'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::INDEXES, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::SEARCHABLEFIELDS, ['indexId'], Table::INDEXES, ['id'], 'CASCADE', null);

        // Only the index is a foreign key: a delete operation has to outlive the element it removes.
        $this->addForeignKey(null, Table::INDEXOPERATIONS, ['indexId'], Table::INDEXES, ['id'], 'CASCADE', null);
    }
}
