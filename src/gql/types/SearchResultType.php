<?php

namespace Tahadudhiya\SearchKit\gql\types;

use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\SearchKit\models\SearchResult;

/**
 * A search, as it came back: what was found, how far through it this window is, and what else the
 * search has to say about the query it ran.
 */
class SearchResultType extends ObjectType
{
    public static function getName(): string
    {
        return 'SearchKit_SearchResult';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new self([
            'name' => self::getName(),
            'description' => 'The result of a Search Kit search.',
            'fields' => self::getFieldDefinitions(...),
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    public static function getFieldDefinitions(): array
    {
        return [
            'index' => [
                'name' => 'index',
                'type' => Type::string(),
                'description' => 'The handle of the index that was searched.',
            ],
            'total' => [
                'name' => 'total',
                'type' => Type::nonNull(Type::int()),
                'description' => 'How many results the search found in total.',
            ],
            'limit' => [
                'name' => 'limit',
                'type' => Type::int(),
                'description' => 'How many results this window may hold.',
            ],
            'offset' => [
                'name' => 'offset',
                'type' => Type::int(),
                'description' => 'Where in the results this window starts.',
            ],
            'page' => [
                'name' => 'page',
                'type' => Type::int(),
                'description' => 'Which page this window is.',
            ],
            'pageCount' => [
                'name' => 'pageCount',
                'type' => Type::int(),
                'description' => 'How many pages of this size the results make up.',
            ],
            'hasNextPage' => [
                'name' => 'hasNextPage',
                'type' => Type::boolean(),
                'description' => 'Whether there are results after this window.',
            ],
            'hasPreviousPage' => [
                'name' => 'hasPreviousPage',
                'type' => Type::boolean(),
                'description' => 'Whether there are results before this window.',
            ],
            'correctedQuery' => [
                'name' => 'correctedQuery',
                'type' => Type::string(),
                'description' => 'What was searched for instead, when the query was corrected.',
            ],
            'suggestions' => [
                'name' => 'suggestions',
                'type' => Type::listOf(Type::string()),
                'description' => 'Queries worth trying instead, offered when this one found nothing.',
            ],
            'redirect' => [
                'name' => 'redirect',
                'type' => Type::string(),
                'description' => 'Where a matching search rule says this query should be sent instead.',
            ],
            'executionTime' => [
                'name' => 'executionTime',
                'type' => Type::float(),
                'description' => 'Milliseconds spent running the search.',
            ],
            'trackingToken' => [
                'name' => 'trackingToken',
                'type' => Type::string(),
                'description' => 'The token a clicked result is reported back under, when this search was recorded.',
            ],
            'facets' => [
                'name' => 'facets',
                'type' => Type::listOf(SearchFacetType::getType()),
                'description' => 'How the whole result set divides up by each field that was counted.',
            ],
            'hits' => [
                'name' => 'hits',
                'type' => Type::listOf(SearchHitType::getType()),
                'description' => 'The results themselves, in the order they were ranked.',
            ],
        ];
    }

    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var SearchResult $source */
        return match ($resolveInfo->fieldName) {
            'index' => $source->indexHandle,
            'correctedQuery' => $source->correctedText,
            default => parent::resolve($source, $arguments, $context, $resolveInfo),
        };
    }
}
