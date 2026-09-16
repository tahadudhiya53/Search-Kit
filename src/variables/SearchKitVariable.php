<?php

namespace Tahadudhiya\SearchKit\variables;

use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\SearchKit;
use yii\base\InvalidConfigException;

/**
 * `craft.searchKit` — everything a template may do with SearchKit. It exposes SearchKit's own query
 * and result objects and nothing else, so no service or provider detail reaches a template.
 */
class SearchKitVariable
{
    /**
     * Runs a search and returns its normalized result. It takes either an index handle and the text
     * to search for, or a query built with `query()`.
     *
     * @param array<string,mixed> $params
     */
    public function search(SearchQuery|string $index, string $text = '', array $params = []): SearchResult
    {
        $query = $index instanceof SearchQuery ? $index : $this->query($index, $text, $params);

        return $this->plugin()->getSearch()->search($query);
    }

    /**
     * Builds a query without running it, for a template that needs to adjust one before searching.
     *
     * @param array<string,mixed> $params
     */
    public function query(string $index, string $text, array $params = []): SearchQuery
    {
        return SearchQuery::create($index, $text, $params);
    }

    private function plugin(): SearchKit
    {
        return SearchKit::getInstance()
            ?? throw new InvalidConfigException('SearchKit is not installed or is disabled.');
    }
}
