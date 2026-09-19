<?php

namespace Tahadudhiya\SearchKit\db;

/**
 * SearchKit's table names, so the migration and the records cannot drift apart.
 */
abstract class Table
{
    public const INDEXES = '{{%searchkit_indexes}}';
    public const SEARCHABLEFIELDS = '{{%searchkit_searchablefields}}';
    public const INDEXOPERATIONS = '{{%searchkit_indexoperations}}';
    public const SYNONYMS = '{{%searchkit_synonyms}}';
    public const TERMS = '{{%searchkit_terms}}';
    public const RULES = '{{%searchkit_rules}}';
    public const RULEACTIONS = '{{%searchkit_ruleactions}}';
    public const SEARCHEVENTS = '{{%searchkit_searchevents}}';
    public const SEARCHCLICKS = '{{%searchkit_searchclicks}}';
}
