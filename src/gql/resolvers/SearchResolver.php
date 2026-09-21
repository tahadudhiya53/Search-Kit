<?php

namespace Tahadudhiya\SearchKit\gql\resolvers;

use Craft;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ResolveInfo;
use Tahadudhiya\SearchKit\enums\FilterOperator;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\SearchKitException;
use Tahadudhiya\SearchKit\models\SearchFilter;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\SearchKit;
use yii\base\InvalidConfigException;

/**
 * Runs a GraphQL search through the same search service PHP and Twig use. What may be searched is
 * decided by the schema: which indexes it names, and which sites it allows.
 */
class SearchResolver
{
    /** @var string The schema component naming one searchable index. */
    public const SCHEMA_PREFIX = 'searchKitIndexes';

    public static function schemaComponent(SearchIndex $index): string
    {
        return self::SCHEMA_PREFIX . '.' . $index->uid;
    }

    /**
     * @param array<string,mixed> $arguments
     */
    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): SearchResult
    {
        $handle = (string)($arguments['index'] ?? '');
        $index = self::plugin()->getIndexes()->getIndexByHandle($handle);

        // An index this schema does not name is reported as though it were not there, so a token
        // can never be used to find out which indexes exist.
        if ($index === null || !GqlHelper::canSchema(self::schemaComponent($index))) {
            throw new UserError("No search index exists with the handle “{$handle}”.");
        }

        try {
            $query = SearchQuery::create($handle, (string)($arguments['q'] ?? ''), self::params($arguments));
            self::assertSitesAreAllowed($query, $index);

            return self::plugin()->getSearch()->search($query);
        } catch (SearchKitException $e) {
            // SearchKit's own messages are written to be shown; nothing else is passed on.
            throw new UserError(self::message($e));
        }
    }

    /**
     * What a refusal says. A query refused over its parameters says which one and why, the same
     * thing the REST layer reports, rather than only that something was wrong.
     */
    private static function message(SearchKitException $e): string
    {
        if (!$e instanceof InvalidQueryException) {
            return $e->getMessage();
        }

        $details = [];

        foreach ($e->getErrors() as $messages) {
            foreach ($messages as $message) {
                $details[] = $message;
            }
        }

        return $details !== [] ? $e->getMessage() . ' ' . implode(' ', array_unique($details)) : $e->getMessage();
    }

    /**
     * A search may only cover sites the schema allows. A search of every site is refused unless the
     * schema allows every one of them, rather than quietly answering for part of the scope.
     */
    private static function assertSitesAreAllowed(SearchQuery $query, SearchIndex $index): void
    {
        $allowed = array_map(static fn($site) => (int)$site->id, GqlHelper::getAllowedSites());
        $scope = $query->getSiteScope($index->siteId);

        if ($scope !== null) {
            if (array_diff($scope, $allowed) !== []) {
                throw new UserError('This schema does not have access to the site being searched.');
            }

            return;
        }

        if (array_diff(Craft::$app->getSites()->getAllSiteIds(true), $allowed) !== []) {
            throw new UserError(
                "The “{$index->handle}” index covers every site, and this schema does not. Name a site to search.",
            );
        }
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private static function params(array $arguments): array
    {
        $params = [];

        foreach (['limit', 'offset', 'page', 'snippetLength', 'highlight', 'site', 'sites', 'facets', 'orderBy'] as $name) {
            if (isset($arguments[$name])) {
                $params[$name] = $arguments[$name];
            }
        }

        if (isset($arguments['filters'])) {
            $params['filters'] = self::filters($arguments['filters']);
        }

        return $params;
    }

    /**
     * @param mixed $filters
     * @return SearchFilter[]
     */
    private static function filters(mixed $filters): array
    {
        $normalized = [];

        foreach (is_array($filters) ? $filters : [] as $filter) {
            $field = (string)($filter['field'] ?? '');
            $name = (string)($filter['operator'] ?? FilterOperator::Equals->value);
            $operator = FilterOperator::tryFrom($name) ?? throw new UserError("“{$name}” is not a filter operator.");
            $values = array_values((array)($filter['value'] ?? []));

            // Every value arrives as a list, so an operator taking one value is given the one it
            // was passed rather than a list of one.
            if (!$operator->expectsArray() && count($values) !== 1) {
                throw new UserError("The {$operator->value} operator expects exactly one value.");
            }

            $normalized[] = SearchFilter::make($field, $operator, $operator->expectsArray() ? $values : $values[0]);
        }

        return $normalized;
    }

    private static function plugin(): SearchKit
    {
        return SearchKit::getInstance()
            ?? throw new InvalidConfigException('SearchKit is not installed or is disabled.');
    }
}
