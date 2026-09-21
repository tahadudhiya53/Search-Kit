<?php

namespace Tahadudhiya\SearchKit\gql\types;

use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\SearchKit\models\Facet;

/**
 * How the whole result set divides up by one field, which is what a filter can be offered from.
 */
class SearchFacetType extends ObjectType
{
    public static function getName(): string
    {
        return 'SearchKit_Facet';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new self([
            'name' => self::getName(),
            'description' => 'How the results divide up by one field.',
            'fields' => [
                'field' => [
                    'name' => 'field',
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The field that was counted.',
                ],
                'values' => [
                    'name' => 'values',
                    'type' => Type::listOf(SearchFacetValueType::getType()),
                    'description' => 'Every value the results hold, commonest first.',
                ],
            ],
        ]));
    }

    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var Facet $source */
        return match ($resolveInfo->fieldName) {
            'values' => $source->getValues(),
            default => parent::resolve($source, $arguments, $context, $resolveInfo),
        };
    }
}
