<?php

namespace Tahadudhiya\SearchKit\gql\types;

use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\Type;

/**
 * One value a facet found, and how many results carry it.
 */
class SearchFacetValueType extends ObjectType
{
    public static function getName(): string
    {
        return 'SearchKit_FacetValue';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new self([
            'name' => self::getName(),
            'description' => 'One value of a facet, with how many results carry it.',
            'fields' => [
                'value' => [
                    'name' => 'value',
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The value itself, in the form a filter would name it.',
                ],
                'count' => [
                    'name' => 'count',
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'How many results carry this value.',
                ],
            ],
        ]));
    }
}
