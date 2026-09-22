<?php

namespace Tahadudhiya\SearchKit\providers;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\QueryAbortedException;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\DateTimeHelper;
use DateTimeInterface;
use Tahadudhiya\SearchKit\base\SearchProvider;
use Tahadudhiya\SearchKit\enums\FilterOperator;
use Tahadudhiya\SearchKit\enums\PartialMatchMode;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\enums\SortDirection;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\errors\SearchKitException;
use Tahadudhiya\SearchKit\events\RegisterFilterCriteriaEvent;
use Tahadudhiya\SearchKit\models\Facet;
use Tahadudhiya\SearchKit\models\ParsedQuery;
use Tahadudhiya\SearchKit\models\QueryTerm;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchFilter;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\models\SearchSort;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component as YiiComponent;

/**
 * Serves searches from Craft's own search index, so SearchKit is useful without external services.
 * Every index using this provider shares that one Craft index; SearchKit scopes them by configured
 * element types and sites rather than by separate physical indexes, which Craft does not offer.
 */
class CraftProvider extends SearchProvider
{
    public const EVENT_REGISTER_FILTER_CRITERIA = 'registerFilterCriteria';

    /** @var string The filter field naming the element types a search is restricted to. */
    public const FILTER_ELEMENT_TYPE = 'elementType';

    /** @var string The filter field naming elements a result has to be related to. */
    public const FILTER_RELATED_TO = 'relatedTo';

    /** @var string The facet field counting results by the kind of element they are. */
    public const FACET_ELEMENT_TYPE = 'elementType';

    /** @var string The facet field counting results by the site they were found in. */
    public const FACET_SITE_ID = 'siteId';

    /** @var int How many values one facet may report per element type, commonest first. */
    private const MAX_FACET_VALUES = 100;

    /**
     * Element query criteria a filter may set on any element type. Anything outside this list, and
     * outside what an integration registers for the type, is either a configured searchable field
     * or rejected — no filter ever reaches the query builder unchecked.
     */
    private const CRITERIA = [
        'id', 'uid', 'title', 'slug', 'uri', 'level',
        'section', 'sectionId', 'type', 'typeId', 'authorId',
        'group', 'groupId', 'volume', 'volumeId', 'folderId', 'kind',
    ];

    /** @var string[] The only things this provider reports that may be shown to a developer. */
    private const DIAGNOSTICS = ['craftQuery', 'elementTypes', 'orderBy'];

    /** @var array<string,array<string,string|callable>> Sortable attributes per element type. */
    private array $_sortOptions = [];

    /** @var array<string,string[]> Criteria a filter may name, per element type, for one request. */
    private array $_criteria = [];

    /** @var array<string,FieldInterface|false> Custom fields resolved per element type and handle. */
    private array $_customFields = [];

    /** @var array<string,string|false> Facet columns resolved per element type and field. */
    private array $_facetColumns = [];

    public static function displayName(): string
    {
        return 'Craft';
    }

    /**
     * Craft scores results itself: it has no per-field weighting, highlighting or typo tolerance,
     * so SearchKit rejects queries asking for those rather than quietly ignoring them. Craft also
     * clears an element's keywords itself on delete, so there is nothing here to delete. Excluded
     * results are left out of the element query, so they are missing from the count as well.
     */
    public static function capabilities(): array
    {
        return [
            ProviderCapability::Search,
            ProviderCapability::Indexing,
            ProviderCapability::Filtering,
            ProviderCapability::Sorting,
            ProviderCapability::Faceting,
            ProviderCapability::PartialMatching,
            ProviderCapability::PhraseMatching,
            ProviderCapability::TermExclusion,
            ProviderCapability::TermAlternation,
            ProviderCapability::ResultExclusion,
        ];
    }

    /**
     * What Craft was asked, which is the query itself and the element types and ordering it ran
     * over. This provider holds no credentials, and still nothing outside this list is shown.
     */
    public function diagnostics(array $metadata): array
    {
        $safe = [];

        foreach (self::DIAGNOSTICS as $key) {
            $value = $metadata[$key] ?? null;

            if (is_string($value) || is_array($value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    public function search(SearchQuery $query, SearchIndex $index): SearchResult
    {
        $indexed = $index->getElementTypes();

        if ($indexed === []) {
            throw new ProviderException("The “{$index->handle}” search index has no enabled searchable fields.");
        }

        $filters = $this->filtersByField($query);
        $elementTypes = $this->elementTypes($indexed, $filters);

        if ($elementTypes === []) {
            return $this->emptyResult($query, $index);
        }

        $sorts = $this->resolveSorts($query, $elementTypes);
        $singleElementType = count($elementTypes) === 1;

        $unusedFilters = $filters;
        $hits = [];
        $total = 0;

        foreach ($elementTypes as $elementType) {
            $applied = [];

            try {
                $elementQuery = $this->createElementQuery($elementType, $query, $index, $sorts, $filters, $applied);
                $unusedFilters = array_diff_key($unusedFilters, $applied);

                if ($elementQuery === null) {
                    continue;
                }

                $total += (int)$elementQuery->count();

                $paginated = (clone $elementQuery)
                    ->limit($singleElementType ? $query->limit : $query->offset + $query->limit);

                if ($singleElementType) {
                    $paginated->offset($query->offset);
                }

                $elements = $paginated->all();
            } catch (SearchKitException $e) {
                throw $e;
            } catch (Throwable $e) {
                Craft::error("Craft could not search {$elementType}: {$e->getMessage()}", SearchKit::LOG_CATEGORY);
                throw new ProviderException("Craft could not search {$elementType}.", 0, $e);
            }

            foreach ($elements as $element) {
                $hits[] = $this->createHit($element, $elementType);
            }
        }

        // A filter no element type could even be asked about is a mistake, not an empty result.
        if ($unusedFilters !== []) {
            $field = (string)array_key_first($unusedFilters);
            throw new InvalidQueryException(
                "“{$field}” cannot be filtered on in the “{$index->handle}” search index.",
                ['filters' => ["“{$field}” is not a filterable field on any element type this index covers."]],
            );
        }

        if (!$singleElementType) {
            $hits = array_slice($this->merge($hits, $sorts), $query->offset, $query->limit);
        }

        return new SearchResult([
            'hits' => $hits,
            'total' => $total,
            'limit' => $query->limit,
            'offset' => $query->offset,
            'indexHandle' => $index->handle,
            'provider' => static::class,
            'metadata' => [
                'elementTypes' => $elementTypes,
                'orderBy' => array_column($sorts, 'field'),
                'craftQuery' => $this->craftSyntax($query->getParsedQuery()),
            ],
        ]);
    }

    /**
     * How the whole result set divides up by each field asked for, counted by the database rather
     * than by reading results back. Counting runs over the search itself, so a facet describes
     * every result rather than the page that was returned.
     *
     * @return Facet[]
     */
    public function facets(SearchQuery $query, SearchIndex $index): array
    {
        $indexed = $index->getElementTypes();

        if ($indexed === []) {
            throw new ProviderException("The “{$index->handle}” search index has no enabled searchable fields.");
        }

        $filters = $this->filtersByField($query);
        $elementTypes = $this->elementTypes($indexed, $filters);

        $counts = array_fill_keys($query->getFacets(), []);
        $answered = [];

        foreach ($elementTypes as $elementType) {
            $applied = [];

            try {
                // No ordering: a count is the same whatever order it would have come back in, and
                // leaving relevance out keeps Craft from scoring a result set nobody is reading.
                $elementQuery = $this->createElementQuery($elementType, $query, $index, [], $filters, $applied);

                if ($elementQuery === null) {
                    continue;
                }

                $this->countFacets($elementQuery, $elementType, $query, $counts, $answered);
            } catch (SearchKitException $e) {
                throw $e;
            } catch (Throwable $e) {
                Craft::error("Craft could not count {$elementType}: {$e->getMessage()}", SearchKit::LOG_CATEGORY);
                throw new ProviderException("Craft could not count results for {$elementType}.", 0, $e);
            }
        }

        // A field nothing could be counted by is a mistake, not a facet that happens to be empty.
        foreach ($query->getFacets() as $field) {
            if ($elementTypes !== [] && !isset($answered[$field])) {
                throw new InvalidQueryException(
                    "“{$field}” cannot be counted in the “{$index->handle}” search index.",
                    ['facets' => ["“{$field}” is not a column any element type this index covers holds."]],
                );
            }
        }

        return array_map(
            static fn(string $field) => Facet::make($field, $counts[$field]),
            $query->getFacets(),
        );
    }

    /**
     * @param class-string<ElementInterface> $elementType
     * @param array<string,array<string,int>> $counts Value counts per facet field, added to here.
     * @param array<string,true> $answered Filled with the facets this element type could answer.
     */
    private function countFacets(
        ElementQueryInterface $elementQuery,
        string $elementType,
        SearchQuery $query,
        array &$counts,
        array &$answered,
    ): void {
        try {
            // The prepared subquery is the search itself with no window on it, which is exactly
            // what a facet counts over.
            $prepared = (clone $elementQuery)->limit(null)->offset(null)->prepareSubquery();
        } catch (QueryAbortedException) {
            // Craft settled the query as matching nothing, which contributes no counts.
            return;
        }

        foreach ($query->getFacets() as $field) {
            if ($field === self::FACET_ELEMENT_TYPE) {
                $answered[$field] = true;
                $key = $elementType::refHandle() ?? $elementType;
                $counts[$field][$key] = ($counts[$field][$key] ?? 0) + (int)(clone $elementQuery)->count();
                continue;
            }

            $column = $this->facetColumn($prepared, $elementType, $field);

            if ($column === null) {
                continue;
            }

            $answered[$field] = true;

            foreach ($this->groupedCounts($prepared, $column) as $value => $count) {
                $counts[$field][$value] = ($counts[$field][$value] ?? 0) + $count;
            }
        }
    }

    /**
     * One value count per row, commonest first. A result is one element in one site, so that is what
     * is counted — the same thing the total counts.
     *
     * @return array<string,int>
     */
    private function groupedCounts(Query $prepared, string $column): array
    {
        $rows = (clone $prepared)
            ->select(['searchKitValue' => $column, 'searchKitCount' => 'COUNT(DISTINCT [[elements_sites.id]])'])
            ->groupBy([$column])
            ->orderBy(['searchKitCount' => SORT_DESC])
            ->limit(self::MAX_FACET_VALUES)
            ->all();

        $counts = [];

        foreach ($rows as $row) {
            // A row with nothing in the column is not a value anyone could filter by.
            if (($row['searchKitValue'] ?? null) !== null && $row['searchKitValue'] !== '') {
                $counts[(string)$row['searchKitValue']] = (int)$row['searchKitCount'];
            }
        }

        return $counts;
    }

    /**
     * The column a facet names, resolved against the tables the search itself joined rather than a
     * list kept here — so an element type Craft or a plugin defines is counted by its own columns,
     * and a name matching no column is refused rather than reaching the database.
     *
     * @param class-string<ElementInterface> $elementType
     */
    private function facetColumn(Query $prepared, string $elementType, string $field): ?string
    {
        if ($field === self::FACET_SITE_ID) {
            return 'elements_sites.siteId';
        }

        $table = $this->elementTable($prepared);

        if ($table === null) {
            return null;
        }

        [$alias, $name] = $table;
        $key = "$elementType:$field";

        if (!isset($this->_facetColumns[$key])) {
            $schema = Craft::$app->getDb()->getTableSchema($name);
            $this->_facetColumns[$key] = $schema !== null && isset($schema->columns[$field])
                ? "$alias.$field"
                : false;
        }

        return $this->_facetColumns[$key] ?: null;
    }

    /**
     * The element type's own table, as the prepared search joined it. Only the join Craft makes for
     * an element's own table is read, so a facet can never reach a table a filter happened to bring in.
     *
     * @return array{string,string}|null The alias and the table name.
     */
    private function elementTable(Query $prepared): ?array
    {
        foreach ($prepared->join ?? [] as $join) {
            $table = $join[1] ?? null;

            if (!is_array($table) || count($table) !== 1) {
                continue;
            }

            $alias = (string)array_key_first($table);

            if (($join[2] ?? null) === "[[$alias.id]] = [[elements.id]]") {
                return [$alias, (string)reset($table)];
            }
        }

        return null;
    }

    /**
     * The query in the form Craft's own parser reads, with its default matching turned off: what a
     * term matches is decided by the pipeline, so nothing here may widen it.
     *
     * @return array<string,mixed>
     */
    private function searchCriteria(SearchQuery $query): array
    {
        return [
            'query' => $this->craftSyntax($query->getParsedQuery()),
            'subLeft' => false,
            'subRight' => false,
            'exclude' => false,
            'exact' => false,
        ];
    }

    /**
     * Writes parsed terms back out in Craft's search syntax. Terms are normalized by the time they
     * reach here, so none of them can carry a character Craft would read as an operator.
     */
    private function craftSyntax(ParsedQuery $parsed): string
    {
        $written = [];

        foreach ($parsed->getTerms() as $term) {
            // Each alternative carries its own matching, so none of them is widened by its neighbour.
            $variants = array_map(fn(QueryTerm $variant) => $this->writeTerm($variant), $term->getVariants());

            // Craft reads “OR” between two terms as either one being enough.
            $written[] = ($term->excluded ? '-' : '') . implode(' OR ', $variants);
        }

        return implode(' ', $written);
    }

    /**
     * A word with the matching it was given, or a phrase quoted so Craft keeps its words together.
     */
    private function writeTerm(QueryTerm $term): string
    {
        if ($term->phrase || str_contains($term->text, ' ')) {
            return '"' . $term->text . '"';
        }

        return match ($term->partial) {
            PartialMatchMode::Substring => '*' . $term->text . '*',
            PartialMatchMode::Prefix => $term->text . '*',
            PartialMatchMode::Off => $term->text,
        };
    }

    /**
     * Craft reads a null field list as “index every field”, so a document carrying no configured
     * fields for this element is a configuration error rather than an implicit index-everything.
     */
    public function indexDocument(SearchIndex $index, SearchDocument $document): void
    {
        $this->assertDocumentIsIndexable($index, $document);

        // Craft's search service indexes an element, not a document, so this provider needs the
        // element the document was built from. The document still decides whether it is indexed.
        $element = $document->getSource();

        if ($element === null) {
            throw new ProviderException("Element {$document->elementId} is no longer available to index.");
        }

        try {
            $indexed = Craft::$app->getSearch()->indexElementAttributes($element, $document->getFieldHandles());
        } catch (Throwable $e) {
            Craft::error("Craft could not index element {$element->id}: {$e->getMessage()}", SearchKit::LOG_CATEGORY);
            throw new ProviderException("Craft could not index element {$element->id}.", 0, $e);
        }

        if (!$indexed) {
            throw new ProviderException("Craft reported a failure indexing element {$element->id}.");
        }
    }

    /**
     * A filter may leave nothing to search, which is an empty result rather than a failure.
     */
    private function emptyResult(SearchQuery $query, SearchIndex $index): SearchResult
    {
        return new SearchResult([
            'limit' => $query->limit,
            'offset' => $query->offset,
            'indexHandle' => $index->handle,
            'provider' => static::class,
            'metadata' => ['elementTypes' => []],
        ]);
    }

    /**
     * @return array<string,SearchFilter>
     */
    private function filtersByField(SearchQuery $query): array
    {
        $filters = [];

        foreach ($query->getFilters() as $filter) {
            // Two constraints on one field would fight each other in a single element query.
            if (isset($filters[$filter->field])) {
                throw new InvalidQueryException(
                    "“{$filter->field}” is filtered on more than once.",
                    ['filters' => ["“{$filter->field}” may only be filtered on once."]],
                );
            }

            $filters[$filter->field] = $filter;
        }

        return $filters;
    }

    /**
     * The element types to search: those the index covers, narrowed by an `elementType` filter.
     *
     * @param string[] $indexed
     * @param array<string,SearchFilter> $filters
     * @return string[]
     */
    private function elementTypes(array $indexed, array &$filters): array
    {
        $filter = $filters[self::FILTER_ELEMENT_TYPE] ?? null;

        if ($filter === null) {
            return $indexed;
        }

        unset($filters[self::FILTER_ELEMENT_TYPE]);

        $wanted = array_map(
            fn(mixed $value) => $this->resolveElementType((string)$value, $indexed),
            is_array($filter->value) ? $filter->value : [$filter->value],
        );

        $excluded = in_array($filter->operator, [FilterOperator::NotEquals, FilterOperator::NotIn], true);

        return array_values(array_filter(
            $indexed,
            static fn(string $type) => in_array($type, $wanted, true) !== $excluded,
        ));
    }

    /**
     * Validates the requested ordering against what Craft can sort each element type by.
     *
     * @param string[] $elementTypes
     * @return array<int,array{field:string,direction:int}>
     */
    private function resolveSorts(SearchQuery $query, array $elementTypes): array
    {
        $sorts = [];
        $mergingInPhp = count($elementTypes) > 1;

        foreach ($query->getSorts() as $sort) {
            $direction = $sort->direction === SortDirection::Asc ? SORT_ASC : SORT_DESC;

            if ($sort->isScore()) {
                $sorts[] = ['field' => SearchSort::FIELD_SCORE, 'direction' => $direction];
                continue;
            }

            foreach ($elementTypes as $elementType) {
                /** @var class-string<ElementInterface> $elementType */
                $option = $this->sortOptions($elementType)[$sort->field] ?? null;

                if ($option === null) {
                    throw new InvalidQueryException(
                        "Results cannot be sorted by “{$sort->field}”.",
                        ['sorts' => ["“{$sort->field}” is not a sortable field on {$elementType}."]],
                    );
                }

                // Craft orders some fields with SQL of its own, and results covering several
                // element types are ordered in PHP, where that SQL cannot be compared.
                if ($mergingInPhp && !is_string($option)) {
                    throw new InvalidQueryException(
                        "Results covering more than one element type cannot be sorted by “{$sort->field}”.",
                        ['sorts' => ["“{$sort->field}” can only sort a search of one element type."]],
                    );
                }
            }

            $sorts[] = ['field' => $sort->field, 'direction' => $direction];
        }

        return $sorts !== [] ? $sorts : [['field' => SearchSort::FIELD_SCORE, 'direction' => SORT_DESC]];
    }

    /**
     * What Craft itself offers as sort options for an element type, which is the only safe source
     * of an ordering: nothing a caller passes is ever used as SQL.
     *
     * @param class-string<ElementInterface> $elementType
     * @return array<string,string|callable> Sortable field => column name, or Craft's own ordering.
     */
    private function sortOptions(string $elementType): array
    {
        if (isset($this->_sortOptions[$elementType])) {
            return $this->_sortOptions[$elementType];
        }

        $sortable = [];

        foreach ($elementType::sortOptions() as $key => $option) {
            if (is_string($key) && is_string($option)) {
                $sortable[$key] = $key;
                continue;
            }

            if (!is_array($option) || !isset($option['orderBy'])) {
                continue;
            }

            $clause = $option['orderBy'];
            $attribute = $option['attribute'] ?? (is_string($clause) ? $clause : null);

            if (is_string($attribute) && (is_string($clause) || is_callable($clause))) {
                $sortable[$attribute] = $clause;
            }
        }

        return $this->_sortOptions[$elementType] = $sortable;
    }

    /**
     * @param class-string<ElementInterface> $elementType
     * @param array<int,array{field:string,direction:int}> $sorts
     * @param array<string,SearchFilter> $filters
     * @param array<string,true> $applied Filled with the filters this element type could accept.
     * @return ElementQueryInterface|null Null when a filter rules this element type out entirely.
     */
    private function createElementQuery(
        string $elementType,
        SearchQuery $query,
        SearchIndex $index,
        array $sorts,
        array $filters,
        array &$applied,
    ): ?ElementQueryInterface {
        // Nothing narrowing the search on either side means every site, which is Craft's `'*'`.
        $scope = $query->getSiteScope($index->siteId);

        $elementQuery = $elementType::find()
            ->search($this->searchCriteria($query))
            ->siteId($scope ?? '*')
            ->orderBy($this->orderBy($elementType, $sorts));

        if ($query->status !== null) {
            $elementQuery->status($query->status);
        }

        $this->excludeElements($elementQuery, $query);

        $usable = true;

        foreach ($filters as $field => $filter) {
            if (!$this->applyFilter($elementQuery, $filter, $index, $elementType)) {
                // The constraint cannot even be expressed here, so this element type cannot match.
                $usable = false;
                continue;
            }

            $applied[$field] = true;
        }

        return $usable ? $elementQuery : null;
    }

    /**
     * Leaves excluded results out of the query itself, so they cannot appear on any page and are not
     * counted either. A removal naming a site takes only that site's version of the element, which
     * is what keeps a rule written for one site out of another.
     */
    private function excludeElements(ElementQueryInterface $elementQuery, SearchQuery $query): void
    {
        $everySite = [];

        foreach ($query->getExcludedElements() as $excluded) {
            if ($excluded['siteId'] === null) {
                $everySite[] = $excluded['elementId'];
                continue;
            }

            $elementQuery->andWhere(['not', [
                'and',
                ['elements.id' => $excluded['elementId']],
                ['elements_sites.siteId' => $excluded['siteId']],
            ]]);
        }

        if ($everySite !== []) {
            $elementQuery->andWhere(['not in', 'elements.id', $everySite]);
        }
    }

    /**
     * @param class-string<ElementInterface> $elementType
     * @param array<int,array{field:string,direction:int}> $sorts
     * @return array<string,mixed>
     */
    private function orderBy(string $elementType, array $sorts): array
    {
        $orderBy = [];

        foreach ($sorts as $sort) {
            if ($sort['field'] === SearchSort::FIELD_SCORE) {
                $orderBy[SearchSort::FIELD_SCORE] = $sort['direction'];
                continue;
            }

            $option = $this->sortOptions($elementType)[$sort['field']];

            // A callable option builds Craft's own ordering, which already carries the direction.
            $orderBy[$sort['field']] = is_string($option)
                ? $sort['direction']
                : $option($sort['direction'], Craft::$app->getDb());
        }

        return $orderBy;
    }

    /**
     * @param class-string<ElementInterface> $elementType
     * @return bool Whether this element type can be asked about the filter at all.
     */
    private function applyFilter(
        ElementQueryInterface $elementQuery,
        SearchFilter $filter,
        SearchIndex $index,
        string $elementType,
    ): bool {
        if ($filter->field === self::FILTER_RELATED_TO) {
            $elementQuery->relatedTo($this->relatedTo($filter));
            return true;
        }

        if (in_array($filter->field, $this->criteria($elementType), true) && method_exists($elementQuery, $filter->field)) {
            $elementQuery->{$filter->field}($this->criteriaValue($filter));
            return true;
        }

        // Otherwise only a field this index actually searches on this element type may be filtered.
        if (!array_key_exists($filter->field, $index->getFieldWeights($elementType))) {
            return false;
        }

        $field = $this->customField($elementType, $filter->field);

        if ($field === null) {
            return false;
        }

        // Craft stores no queryable value for some field types, and would answer a filter on one
        // with no results at all rather than saying it could not apply it.
        if ($field::dbType() === null) {
            throw new InvalidQueryException(
                "“{$filter->field}” cannot be filtered on.",
                ['filters' => ["“{$filter->field}” is a field type Craft cannot query."]],
            );
        }

        $elementQuery->{$filter->field} = $this->criteriaValue($filter);

        return true;
    }

    /**
     * The criteria a filter may name on an element type: SearchKit's own, plus whatever an
     * integration registers for it. A criterion the installed element query does not actually
     * define is still refused, so this can never depend on a plugin's version.
     *
     * @param class-string<ElementInterface> $elementType
     * @return string[]
     */
    private function criteria(string $elementType): array
    {
        if (isset($this->_criteria[$elementType])) {
            return $this->_criteria[$elementType];
        }

        $event = new RegisterFilterCriteriaEvent([
            'elementType' => $elementType,
            'criteria' => self::CRITERIA,
        ]);

        $this->trigger(self::EVENT_REGISTER_FILTER_CRITERIA, $event);

        return $this->_criteria[$elementType] = array_values(array_unique($event->criteria));
    }

    /**
     * A relationship constraint as element IDs and nothing else, so a filter can never hand Craft a
     * relation criteria structure of its own.
     *
     * @return array<string,int[]>
     */
    private function relatedTo(SearchFilter $filter): array
    {
        // Craft has no “related to none of these”, so a negation is refused rather than approximated.
        if (!in_array($filter->operator, [FilterOperator::Equals, FilterOperator::In], true)) {
            throw new InvalidQueryException(
                'Results cannot be filtered out by relationship.',
                ['filters' => ['“relatedTo” only supports the equals and in operators.']],
            );
        }

        $ids = [];

        foreach (is_array($filter->value) ? $filter->value : [$filter->value] as $value) {
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw new InvalidQueryException(
                    'Related elements have to be named by ID.',
                    ['filters' => ['“relatedTo” expects element IDs.']],
                );
            }

            $ids[] = (int)$value;
        }

        return ['element' => $ids];
    }

    /**
     * The custom field behind a handle on an element type, or null if it has none. Only a field on
     * one of that type's own layouts counts, so a handle can never reach another element type.
     *
     * @param class-string<ElementInterface> $elementType
     */
    private function customField(string $elementType, string $handle): ?FieldInterface
    {
        $key = "$elementType:$handle";

        if (!isset($this->_customFields[$key])) {
            $this->_customFields[$key] = false;

            foreach (Craft::$app->getFields()->getLayoutsByType($elementType) as $layout) {
                $field = $layout->getFieldByHandle($handle);

                if ($field !== null) {
                    $this->_customFields[$key] = $field;
                    break;
                }
            }
        }

        return $this->_customFields[$key] ?: null;
    }

    /**
     * A filter in the form Craft's element queries expect, built only from vetted operators.
     */
    private function criteriaValue(SearchFilter $filter): mixed
    {
        $value = is_array($filter->value)
            ? array_map(fn(mixed $item) => $this->scalar($item), $filter->value)
            : $this->scalar($filter->value);

        return match ($filter->operator) {
            FilterOperator::Equals => $value,
            FilterOperator::NotEquals => ['not', $value],
            FilterOperator::In => $value,
            FilterOperator::NotIn => array_merge(['not'], (array)$value),
            FilterOperator::GreaterThan => "> $value",
            FilterOperator::GreaterThanOrEquals => ">= $value",
            FilterOperator::LessThan => "< $value",
            FilterOperator::LessThanOrEquals => "<= $value",
            // Craft's own form for a range, so a bound is never written into SQL here.
            FilterOperator::Between => ['and', '>= ' . ((array)$value)[0], '<= ' . ((array)$value)[1]],
        };
    }

    private function scalar(mixed $value): string|int|float|bool|null
    {
        return $value instanceof DateTimeInterface ? DateTimeHelper::toIso8601($value) : $value;
    }

    /**
     * Orders hits drawn from several element types, which Craft can only sort within one query.
     * Every sort reaching here was checked to be a plain attribute each element carries.
     *
     * @param SearchHit[] $hits
     * @param array<int,array{field:string,direction:int}> $sorts
     * @return SearchHit[]
     */
    private function merge(array $hits, array $sorts): array
    {
        usort($hits, function(SearchHit $a, SearchHit $b) use ($sorts) {
            foreach ($sorts as $sort) {
                $comparison = $this->compare(
                    $this->sortValue($a, $sort['field']),
                    $this->sortValue($b, $sort['field']),
                );

                if ($comparison !== 0) {
                    return $sort['direction'] === SORT_ASC ? $comparison : -$comparison;
                }
            }

            // Element IDs break every remaining tie, so a page is never ordered arbitrarily.
            return $a->elementId <=> $b->elementId;
        });

        return $hits;
    }

    /**
     * Compares text the way the database ordered it, so a merge cannot disagree with the queries.
     */
    private function compare(mixed $a, mixed $b): int
    {
        if (is_string($a) && is_string($b)) {
            return strcasecmp($a, $b) <=> 0;
        }

        return $a <=> $b;
    }

    private function sortValue(SearchHit $hit, string $field): mixed
    {
        if ($field === SearchSort::FIELD_SCORE) {
            return $hit->score;
        }

        $element = $hit->element;

        if (!$element instanceof YiiComponent || !$element->canGetProperty($field)) {
            return null;
        }

        $value = $element->$field;

        return $value instanceof DateTimeInterface ? $value->getTimestamp() : $value;
    }

    private function createHit(ElementInterface $element, string $elementType): SearchHit
    {
        return new SearchHit([
            'elementId' => (int)$element->id,
            'siteId' => $element->siteId,
            'elementType' => $elementType,
            'score' => (float)($element->searchScore ?? 0),
            'element' => $element,
        ]);
    }
}
