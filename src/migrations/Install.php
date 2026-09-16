<?php

namespace Tahadudhiya\SearchKit\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use Tahadudhiya\SearchKit\db\Table;

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
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::INDEXES, ['handle'], true);
        $this->createIndex(null, Table::INDEXES, ['siteId'], false);
        $this->createIndex(null, Table::SEARCHABLEFIELDS, ['indexId', 'elementType', 'handle'], true);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::INDEXES, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::SEARCHABLEFIELDS, ['indexId'], Table::INDEXES, ['id'], 'CASCADE', null);
    }
}
