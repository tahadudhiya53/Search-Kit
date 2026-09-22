<?php

namespace Tahadudhiya\SearchKit\variables;

use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\SearchKit;

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

        return SearchKit::instance()->getSearch()->search($query);
    }

    /**
     * Completions for what has been typed so far, drawn from the words the index holds.
     *
     * @param array<string,mixed> $params
     * @return string[]
     */
    public function autocomplete(string $index, string $text, array $params = []): array
    {
        $query = $this->query($index, $text, $params);

        return SearchKit::instance()->getSearch()->autocomplete($query, $this->suggestionLimit($query, $params));
    }

    /**
     * Queries worth trying instead of this one. A search that found nothing already carries these.
     *
     * @param array<string,mixed> $params
     * @return string[]
     */
    public function suggest(string $index, string $text, array $params = []): array
    {
        $query = $this->query($index, $text, $params);

        return SearchKit::instance()->getSearch()->suggest($query, $this->suggestionLimit($query, $params));
    }

    /**
     * How many suggestions were asked for, or null when none were, which leaves it to the index.
     *
     * @param array<string,mixed> $params
     */
    private function suggestionLimit(SearchQuery $query, array $params): ?int
    {
        return array_key_exists('limit', $params) ? $query->limit : null;
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
}
