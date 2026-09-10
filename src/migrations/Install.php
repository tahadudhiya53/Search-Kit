<?php

namespace Tahadudhiya\SearchKit\migrations;

use craft\db\Migration;

/**
 * Installs and uninstalls SearchKit's schema.
 *
 * SearchKit has no tables yet — indexes, sources, rules, analytics and provider configuration are
 * being designed in a later phase. This migration exists so that lifecycle is already wired: Craft
 * runs `safeUp()` on install and `safeDown()` on uninstall, and adding a `createTable()` here (plus
 * a matching `dropTableIfExists()` below and a `schemaVersion` bump in `SearchKit`) is the whole
 * change needed when the schema arrives.
*/
class Install extends Migration
{
    public function safeUp(): bool
    {
        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
