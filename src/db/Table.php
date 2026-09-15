<?php

namespace Tahadudhiya\SearchKit\db;

/**
 * SearchKit's table names, so the migration and the records cannot drift apart.
 */
abstract class Table
{
    public const INDEXES = '{{%searchkit_indexes}}';
    public const SEARCHABLEFIELDS = '{{%searchkit_searchablefields}}';
}
