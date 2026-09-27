<?php

namespace Tahadudhiya\SearchKit\gql\queries;

use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\SearchKit\gql\resolvers\SearchResolver;
use Tahadudhiya\SearchKit\gql\types\SearchFilterInputType;
use Tahadudhiya\SearchKit\gql\types\SearchResultType;
use Tahadudhiya\SearchKit\SearchKit;

/**
 * The search SearchKit adds to Craft's GraphQL API. It is only offered to a schema that names at
 * least one searchable index.
 */
class SearchQueries extends Query
{
    /**
     * @return array<string,mixed>
     */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !self::schemaHasAnIndex()) {
            return [];
        }

        return [
            'searchKitSearch' => [
                'type' => SearchResultType::getType(),
                'args' => self::getArguments(),
                'resolve' => SearchResolver::class . '::resolve',
                'description' => 'Runs a Search Kit search and returns its results.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function getArguments(): array
    {
        return [
            'index' => [
                'name' => 'index',
                'type' => Type::nonNull(Type::string()),
                'description' => 'The handle of the search index to search.',
            ],
            'q' => [
                'name' => 'q',
                'type' => Type::nonNull(Type::string()),
                'description' => 'What to search for.',
            ],
            'site' => [
                'name' => 'site',
                'type' => Type::string(),
                'description' => 'The handle of the only site to search. May narrow the index’s scope, never widen it.',
            ],
            'sites' => [
                'name' => 'sites',
                'type' => Type::listOf(Type::string()),
                'description' => 'The handles of the sites to search. May narrow the index’s scope, never widen it.',
            ],
            'facets' => [
                'name' => 'facets',
                'type' => Type::listOf(Type::string()),
                'description' => 'Fields to count the whole result set by, such as `sectionId` or `elementType`.',
            ],
            'limit' => [
                'name' => 'limit',
                'type' => Type::int(),
                'description' => 'How many results to return.',
            ],
            'offset' => [
                'name' => 'offset',
                'type' => Type::int(),
                'description' => 'Where in the results to start.',
            ],
            'page' => [
                'name' => 'page',
                'type' => Type::int(),
                'description' => 'Which page of results to return, worked out from the limit.',
            ],
            'orderBy' => [
                'name' => 'orderBy',
                'type' => Type::string(),
                'description' => 'How to order the results, such as `title asc`. Relevance by default.',
            ],
            'filters' => [
                'name' => 'filters',
                'type' => Type::listOf(SearchFilterInputType::getType()),
                'description' => 'Constraints on what may match.',
            ],
            'highlight' => [
                'name' => 'highlight',
                'type' => Type::boolean(),
                'description' => 'Whether to work out matched fields, snippets and highlights.',
            ],
            'snippetLength' => [
                'name' => 'snippetLength',
                'type' => Type::int(),
                'description' => 'Roughly how long a snippet may run, in characters.',
            ],
        ];
    }

    private static function schemaHasAnIndex(): bool
    {
        $plugin = SearchKit::getInstance();

        if ($plugin === null) {
            return false;
        }

        foreach ($plugin->getIndexes()->getAllIndexes() as $index) {
            if (GqlHelper::canSchema(SearchResolver::schemaComponent($index))) {
                return true;
            }
        }

        return false;
    }
}
