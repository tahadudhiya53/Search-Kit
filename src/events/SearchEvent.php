<?php

namespace Tahadudhiya\SearchKit\events;

use craft\base\Event;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;

/**
 * The seam analytics and ranking explanations hang off, so search execution never has to know
 * that either exists.
 */
class SearchEvent extends Event
{
    public SearchQuery $query;
    public SearchIndex $index;
    public ?SearchResult $result = null;
}
