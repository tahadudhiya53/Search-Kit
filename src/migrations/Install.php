<?php

namespace Tahadudhiya\SearchKit\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\enums\IndexOperationStatus;
use Tahadudhiya\SearchKit\services\Analytics;
use Tahadudhiya\SearchKit\services\Terms;

/**
 * Installs and uninstalls SearchKit's schema.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createAnalyticsTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        // Dropped child-first so the foreign keys go with the tables.
        $this->dropTableIfExists(Table::SEARCHCLICKS);
        $this->dropTableIfExists(Table::SEARCHEVENTS);
        $this->dropTableIfExists(Table::RULEACTIONS);
        $this->dropTableIfExists(Table::RULES);
        $this->dropTableIfExists(Table::TERMS);
        $this->dropTableIfExists(Table::SYNONYMS);
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
            // How this index treats the text it is searched with, rather than what serves it.
            'searchSettings' => $this->text(),
            // Whether this index's searches are recorded, and for how long they are kept.
            'analyticsSettings' => $this->text(),
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

        // Words a search is treated as covering as well as the ones it was given. A null index or
        // site means every one of them, so one group can govern a whole installation.
        $this->createTable(Table::SYNONYMS, [
            'id' => $this->primaryKey(),
            'indexId' => $this->integer(),
            'siteId' => $this->integer(),
            'type' => $this->string(16)->notNull(),
            'terms' => $this->text()->notNull(),
            'replacements' => $this->text(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // The words an index holds, one row per word per document. Owning the rows by element is
        // what lets a word disappear when the last document using it stops using it, and what lets
        // a suggestion be checked against content the viewer is actually allowed to find.
        $this->createTable(Table::TERMS, [
            'id' => $this->primaryKey(),
            'indexId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'elementType' => $this->string()->notNull(),
            'term' => $this->string(Terms::MAX_LENGTH)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
        ]);

        // Deliberate control over what a search returns. A rule belongs to one index; a null site
        // means it applies in every site that index covers.
        $this->createTable(Table::RULES, [
            'id' => $this->primaryKey(),
            'indexId' => $this->integer()->notNull(),
            'siteId' => $this->integer(),
            'name' => $this->string()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            // Higher runs first, so a rule can be given the last word over the ones beneath it.
            'priority' => $this->integer()->notNull()->defaultValue(0),
            'matchType' => $this->string(16)->notNull(),
            'matchValue' => $this->string()->notNull(),
            'dateStart' => $this->dateTime(),
            'dateEnd' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // What a matching rule does. One table for every action type, so a new kind of control is
        // a new value here rather than a new table.
        $this->createTable(Table::RULEACTIONS, [
            'id' => $this->primaryKey(),
            'ruleId' => $this->integer()->notNull(),
            'type' => $this->string(16)->notNull(),
            'elementId' => $this->integer(),
            'elementType' => $this->string(),
            // The site the action acts in. Null means every site the rule covers; a placed result
            // always names one, since it has to be put somewhere in particular.
            'siteId' => $this->integer(),
            'amount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'position' => $this->integer(),
            'value' => $this->text(),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    /**
     * What was searched for, and what came back. Nothing here identifies who searched: no account,
     * no address and no session, so the table describes search behaviour and nothing else.
     */
    private function createAnalyticsTables(): void
    {
        $this->createTable(Table::SEARCHEVENTS, [
            'id' => $this->primaryKey(),
            'indexId' => $this->integer()->notNull(),
            // The site searched, or null for a search covering the whole of an index's scope.
            'siteId' => $this->integer(),
            'query' => $this->string(Analytics::MAX_QUERY_LENGTH)->notNull(),
            // The same text reduced the way the index reduces it, which is what queries group by.
            'normalizedQuery' => $this->string(Analytics::MAX_QUERY_LENGTH)->notNull(),
            'correctedQuery' => $this->string(Analytics::MAX_QUERY_LENGTH),
            'language' => $this->string(24)->notNull(),
            'resultCount' => $this->integer()->notNull()->defaultValue(0),
            // The results this search actually returned, which is the only thing a click may name.
            // Bounded, so a wide result window cannot turn one search into unbounded storage.
            'trackedResults' => $this->text(),
            'executionTime' => $this->decimal(12, 3)->notNull()->defaultValue(0),
            // Kept alongside the clicks themselves so a click-through rate needs no join.
            'clickCount' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            // The token a result carries back when it is clicked, so a click needs no identity.
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::SEARCHCLICKS, [
            'id' => $this->primaryKey(),
            'eventId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            'elementType' => $this->string()->notNull(),
            'siteId' => $this->integer()->notNull(),
            // Where the clicked result sat in the search it came from, counted from one.
            'position' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
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

        $this->createIndex(null, Table::SYNONYMS, ['indexId', 'enabled'], false);
        $this->createIndex(null, Table::SYNONYMS, ['siteId'], false);

        // One row per word per document, and the index a prefix or length lookup reads.
        $this->createIndex(null, Table::TERMS, ['indexId', 'elementId', 'siteId', 'term'], true);
        $this->createIndex(null, Table::TERMS, ['indexId', 'siteId', 'term'], false);

        // Every search reads the rules for its index, in the order they are applied.
        $this->createIndex(null, Table::RULES, ['indexId', 'enabled'], false);
        $this->createIndex(null, Table::RULES, ['siteId'], false);
        $this->createIndex(null, Table::RULEACTIONS, ['ruleId'], false);
        $this->createIndex(null, Table::RULEACTIONS, ['elementId'], false);
        $this->createIndex(null, Table::RULEACTIONS, ['siteId'], false);

        // Every reading of search activity is one index over a date range, then grouped by query.
        $this->createIndex(null, Table::SEARCHEVENTS, ['indexId', 'dateCreated'], false);
        $this->createIndex(null, Table::SEARCHEVENTS, ['indexId', 'normalizedQuery'], false);
        $this->createIndex(null, Table::SEARCHEVENTS, ['indexId', 'resultCount'], false);
        $this->createIndex(null, Table::SEARCHEVENTS, ['siteId'], false);
        // The click token, which is the only way back from a click to the search it came from.
        $this->createIndex(null, Table::SEARCHEVENTS, ['uid'], true);

        // One click per result per search: a second report of the same one cannot inflate a rate.
        $this->createIndex(null, Table::SEARCHCLICKS, ['eventId', 'elementId', 'siteId'], true);
        $this->createIndex(null, Table::SEARCHCLICKS, ['elementId'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::INDEXES, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::SEARCHABLEFIELDS, ['indexId'], Table::INDEXES, ['id'], 'CASCADE', null);

        // Only the index is a foreign key: a delete operation has to outlive the element it removes.
        $this->addForeignKey(null, Table::INDEXOPERATIONS, ['indexId'], Table::INDEXES, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::SYNONYMS, ['indexId'], Table::INDEXES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::SYNONYMS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::TERMS, ['indexId'], Table::INDEXES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::TERMS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);

        // A word cannot outlive the element it was read from, however the element goes away.
        $this->addForeignKey(null, Table::TERMS, ['elementId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::RULES, ['indexId'], Table::INDEXES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::RULES, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::RULEACTIONS, ['ruleId'], Table::RULES, ['id'], 'CASCADE', null);

        // An action naming a deleted element has nothing left to do, so it goes with it.
        $this->addForeignKey(null, Table::RULEACTIONS, ['elementId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::RULEACTIONS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::SEARCHEVENTS, ['indexId'], Table::INDEXES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::SEARCHEVENTS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::SEARCHCLICKS, ['eventId'], Table::SEARCHEVENTS, ['id'], 'CASCADE', null);
    }
}
