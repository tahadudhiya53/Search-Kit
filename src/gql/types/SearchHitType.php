<?php

namespace Tahadudhiya\SearchKit\gql\types;

use craft\base\ElementInterface;
use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\SearchKit\models\SearchHit;

/**
 * One result, as what it is and where it ranked. The content behind it is the element's to give
 * out through its own queries, where the schema decides which fields may be read.
 */
class SearchHitType extends ObjectType
{
    public static function getName(): string
    {
        return 'SearchKit_SearchHit';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new self([
            'name' => self::getName(),
            'description' => 'One search result: which element matched, in which site, and how it ranked.',
            'fields' => self::getFieldDefinitions(...),
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    public static function getFieldDefinitions(): array
    {
        return [
            'elementId' => [
                'name' => 'elementId',
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the element that matched.',
            ],
            'siteId' => [
                'name' => 'siteId',
                'type' => Type::int(),
                'description' => 'The ID of the site the element matched in.',
            ],
            'elementType' => [
                'name' => 'elementType',
                'type' => Type::string(),
                'description' => 'The kind of element that matched, such as `entry` or `category`.',
            ],
            'score' => [
                'name' => 'score',
                'type' => Type::float(),
                'description' => 'The relevance the provider itself reported.',
            ],
            'scoreAdjustment' => [
                'name' => 'scoreAdjustment',
                'type' => Type::float(),
                'description' => 'How far search rules moved this result from the provider’s score.',
            ],
            'finalScore' => [
                'name' => 'finalScore',
                'type' => Type::float(),
                'description' => 'The score this result was ranked by.',
            ],
            'pinned' => [
                'name' => 'pinned',
                'type' => Type::boolean(),
                'description' => 'Whether a search rule placed this result at a position of its own.',
            ],
            'promoted' => [
                'name' => 'promoted',
                'type' => Type::boolean(),
                'description' => 'Whether a search rule put this result into the search.',
            ],
            'matchedFields' => [
                'name' => 'matchedFields',
                'type' => Type::listOf(Type::string()),
                'description' => 'The handles of the fields this result matched on.',
            ],
            'snippet' => [
                'name' => 'snippet',
                'type' => Type::string(),
                'args' => [
                    'field' => [
                        'name' => 'field',
                        'type' => Type::string(),
                        'description' => 'The field to excerpt, or the best one available.',
                    ],
                ],
                'description' => 'A plain-text excerpt of the matched text.',
            ],
            'highlight' => [
                'name' => 'highlight',
                'type' => Type::string(),
                'args' => [
                    'field' => [
                        'name' => 'field',
                        'type' => Type::string(),
                        'description' => 'The field to excerpt, or the best one available.',
                    ],
                ],
                'description' => 'The same excerpt with the matched terms wrapped in `mark` elements.',
            ],
        ];
    }

    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var SearchHit $source */
        $field = is_string($arguments['field'] ?? null) ? $arguments['field'] : null;

        return match ($resolveInfo->fieldName) {
            'elementType' => self::elementType($source),
            'finalScore' => $source->getFinalScore(),
            'snippet' => $source->getSnippet($field),
            'highlight' => (string)$source->getHighlight($field) ?: null,
            default => parent::resolve($source, $arguments, $context, $resolveInfo),
        };
    }

    /**
     * The same vocabulary a filter uses, rather than a class name.
     */
    private static function elementType(SearchHit $hit): ?string
    {
        $elementType = $hit->elementType;

        if ($elementType === null || !is_subclass_of($elementType, ElementInterface::class)) {
            return $elementType;
        }

        return $elementType::refHandle() ?? $elementType;
    }
}
