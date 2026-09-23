<?php

namespace Tahadudhiya\SearchKit\gql\types;

use craft\gql\GqlEntityRegistry;
use craft\gql\types\QueryArgument;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * One constraint on a search, in the same terms PHP and Twig express one in.
 */
class SearchFilterInputType extends InputObjectType
{
    public static function getName(): string
    {
        return 'SearchKit_FilterInput';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new self([
            'name' => self::getName(),
            'description' => 'A constraint on what a search may match.',
            'fields' => [
                'field' => [
                    'name' => 'field',
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'What to constrain, such as `section` or a searchable field handle.',
                ],
                'operator' => [
                    'name' => 'operator',
                    'type' => Type::string(),
                    'defaultValue' => 'eq',
                    'description' => 'One of `eq`, `neq`, `in`, `notIn`, `gt`, `gte`, `lt`, `lte` or `between`.',
                ],
                'value' => [
                    'name' => 'value',
                    'type' => Type::nonNull(Type::listOf(QueryArgument::getType())),
                    'description' => 'The values to compare against. `in` and `notIn` take several, `between` takes a lowest and a highest, and every other operator takes one.',
                ],
            ],
        ]));
    }
}
