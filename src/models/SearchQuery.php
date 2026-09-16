<?php

namespace Tahadudhiya\SearchKit\models;

use Craft;
use craft\base\Model;
use craft\models\Site;
use craft\validators\HandleValidator;
use InvalidArgumentException;
use Tahadudhiya\SearchKit\enums\FilterOperator;
use Tahadudhiya\SearchKit\enums\SortDirection;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;

/**
 * A search request in SearchKit's own terms. It never carries provider request structures.
 */
class SearchQuery extends Model
{
    public const MAX_LIMIT = 1000;
    public const MAX_SNIPPET_LENGTH = 1000;

    public string $text = '';
    public string $indexHandle = '';

    /** @var int|null Restricts the search to one site, or null to search the index's whole scope. */
    public ?int $siteId = null;
    public ?string $status = null;
    public int $limit = 20;
    public int $offset = 0;

    /** @var bool Whether matched fields, snippets and highlights should be worked out for each hit. */
    public bool $highlight = false;

    /** @var int Roughly how many characters a snippet may run to. */
    public int $snippetLength = 200;

    /** @var SearchFilter[] */
    private array $_filters = [];

    /** @var SearchSort[] */
    private array $_sorts = [];

    /**
     * The one way to build a query. Every parameter is vetted here, so nothing a caller passes —
     * a template above all — reaches a provider unchecked.
     *
     * @param array<string,mixed> $params
     */
    public static function create(string $indexHandle, string $text, array $params = []): self
    {
        $query = new self([
            'text' => $text,
            'indexHandle' => $indexHandle,
        ]);

        return $query->configure($params);
    }

    /**
     * @param array<string,mixed> $params
     */
    public function configure(array $params): static
    {
        $page = null;

        foreach ($params as $name => $value) {
            match ($name) {
                'limit' => $this->limit = $this->integer($value, 'limit'),
                'offset' => $this->offset = $this->integer($value, 'offset'),
                'page' => $page = $this->integer($value, 'page'),
                'status' => $this->status = $this->text($value, 'status'),
                'highlight' => $this->highlight = $this->boolean($value, 'highlight'),
                'snippetLength' => $this->snippetLength = $this->integer($value, 'snippetLength'),
                'site', 'siteId' => $this->siteId = $this->resolveSiteId($value),
                'filters' => $this->setFilters($this->normalizeFilters($value)),
                'orderBy' => $this->setSorts($this->normalizeSorts($value)),
                default => throw $this->rejected("“{$name}” is not a search parameter."),
            };
        }

        // A page is only meaningful against the final limit, so it is applied once the whole set
        // has been read rather than in whatever order the caller happened to write it.
        if ($page !== null) {
            $this->setPage($page);
        }

        return $this;
    }

    /**
     * Trimmed, whitespace-collapsed query text — the form providers and the debugger both work from.
     */
    public function getNormalizedText(): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $this->text));
    }

    /**
     * The query text reduced to the terms a match can be explained by.
     *
     * @return string[]
     */
    public function getTokens(): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($this->getNormalizedText()), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($tokens ?: []));
    }

    /**
     * @return SearchFilter[]
     */
    public function getFilters(): array
    {
        return $this->_filters;
    }

    /**
     * @param array<mixed> $filters Vetted here, so untrusted input can never reach a provider.
     */
    public function setFilters(array $filters): static
    {
        $this->_filters = array_values($this->onlyInstancesOf($filters, SearchFilter::class, 'filter'));
        return $this;
    }

    public function addFilter(SearchFilter $filter): static
    {
        $this->_filters[] = $filter;
        return $this;
    }

    /**
     * @return SearchSort[]
     */
    public function getSorts(): array
    {
        return $this->_sorts;
    }

    /**
     * @param array<mixed> $sorts Vetted here, so untrusted input can never reach a provider.
     */
    public function setSorts(array $sorts): static
    {
        $this->_sorts = array_values($this->onlyInstancesOf($sorts, SearchSort::class, 'sort'));
        return $this;
    }

    /**
     * Rejects the wrong object up front, so a bad directive fails here rather than mid-validation.
     *
     * @template T of object
     * @param array<mixed> $values
     * @param class-string<T> $class
     * @return T[]
     */
    private function onlyInstancesOf(array $values, string $class, string $label): array
    {
        foreach ($values as $value) {
            if (!$value instanceof $class) {
                $given = get_debug_type($value);
                throw new InvalidArgumentException("Each $label must be a $class instance, $given given.");
            }
        }

        return $values;
    }

    public function addSort(string $field, SortDirection $direction = SortDirection::Desc): static
    {
        $this->_sorts[] = SearchSort::make($field, $direction);
        return $this;
    }

    /**
     * Whether anything beyond provider relevance ordering was asked for.
     */
    public function hasCustomSort(): bool
    {
        foreach ($this->_sorts as $sort) {
            if (!$sort->isScore()) {
                return true;
            }
        }

        return false;
    }

    public function getPage(): int
    {
        return $this->limit > 0 ? (int)floor($this->offset / $this->limit) + 1 : 1;
    }

    public function setPage(int $page): static
    {
        $this->offset = max(0, $page - 1) * $this->limit;
        return $this;
    }

    protected function defineRules(): array
    {
        return [
            [['indexHandle'], 'required'],
            [['indexHandle'], HandleValidator::class],
            // Empty text is exactly what needs rejecting, so validation is not skipped for it.
            [['text'], 'validateText', 'skipOnEmpty' => false],
            [['limit'], 'integer', 'min' => 1, 'max' => self::MAX_LIMIT],
            [['offset'], 'integer', 'min' => 0],
            [['snippetLength'], 'integer', 'min' => 1, 'max' => self::MAX_SNIPPET_LENGTH],
            [['siteId'], 'integer', 'min' => 1],
            [['status'], 'string', 'max' => 255],
        ];
    }

    /**
     * Filters and sorts are objects rather than attributes, so they are validated alongside the query.
     */
    public function afterValidate(): void
    {
        foreach (['filters' => $this->_filters, 'sorts' => $this->_sorts] as $attribute => $directives) {
            foreach ($directives as $directive) {
                if (!$directive->validate()) {
                    foreach ($directive->getFirstErrors() as $error) {
                        $this->addError($attribute, $error);
                    }
                }
            }
        }

        parent::afterValidate();
    }

    public function validateText(string $attribute): void
    {
        if ($this->getNormalizedText() === '') {
            $this->addError($attribute, 'Search text cannot be blank.');
        }
    }

    /**
     * Everything a caller passes fails the same way, so one error type covers the whole public API.
     */
    private function rejected(string $message, string $attribute = 'params'): InvalidQueryException
    {
        return new InvalidQueryException($message, [$attribute => [$message]]);
    }

    /**
     * A whole number, or the string form of one. Request parameters arrive as strings, so `'2'` is
     * a page number; `'2.5'`, `2.5`, `true`, `[]` and `'soon'` are mistakes.
     */
    private function integer(mixed $value, string $name): int
    {
        $integer = is_int($value) || is_float($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT)
            : false;

        if ($integer === false) {
            throw $this->rejected("“{$name}” must be a whole number.", $name);
        }

        return $integer;
    }

    /**
     * Only a real boolean. `'false'` and `0` each have two plausible readings, and PHP's own answer
     * is not the one a template would expect.
     */
    private function boolean(mixed $value, string $name): bool
    {
        if (!is_bool($value)) {
            throw $this->rejected("“{$name}” must be true or false.", $name);
        }

        return $value;
    }

    private function text(mixed $value, string $name): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            throw $this->rejected("“{$name}” must be a non-empty string.", $name);
        }

        return trim($value);
    }

    private function resolveSiteId(mixed $site): ?int
    {
        if ($site === null) {
            return null;
        }

        if ($site instanceof Site) {
            return (int)$site->id;
        }

        if (is_int($site) || (is_string($site) && filter_var($site, FILTER_VALIDATE_INT) !== false)) {
            return $this->integer($site, 'siteId');
        }

        if (!is_string($site) || trim($site) === '') {
            throw $this->rejected('A site must be given as a handle, an ID or a Site model.', 'siteId');
        }

        $resolved = Craft::$app->getSites()->getSiteByHandle($site, true);

        if ($resolved === null) {
            throw $this->rejected("No site exists with the handle “{$site}”.", 'siteId');
        }

        return (int)$resolved->id;
    }

    /**
     * Accepts filters as objects, as `field: value` pairs, or as `field: {operator: value}` pairs,
     * so a template can express a constraint without building models.
     *
     * @return SearchFilter[]
     */
    private function normalizeFilters(mixed $filters): array
    {
        if (!is_array($filters)) {
            throw $this->rejected('Filters must be given as an array.', 'filters');
        }

        $normalized = [];

        foreach ($filters as $field => $value) {
            if ($value instanceof SearchFilter) {
                $normalized[] = $value;
                continue;
            }

            if (is_int($field)) {
                $normalized[] = $this->filterFromDefinition($value);
                continue;
            }

            foreach ($this->operatorPairs($value) as [$operator, $operand]) {
                $normalized[] = SearchFilter::make($this->text($field, 'filters') ?? '', $operator, $operand);
            }
        }

        return $normalized;
    }

    /**
     * @return array<array{FilterOperator,mixed}>
     */
    private function operatorPairs(mixed $value): array
    {
        if (is_array($value) && $value !== [] && !array_is_list($value)) {
            $pairs = [];

            foreach ($value as $operator => $operand) {
                $pairs[] = [$this->operator($operator), $operand];
            }

            return $pairs;
        }

        // A list means “any of these”, which is the only reading that cannot be mistaken.
        return [[is_array($value) ? FilterOperator::In : FilterOperator::Equals, $value]];
    }

    private function filterFromDefinition(mixed $definition): SearchFilter
    {
        if (!is_array($definition) || !isset($definition['field'])) {
            throw $this->rejected('Each filter needs a field, an operator and a value.', 'filters');
        }

        return SearchFilter::make(
            $this->text($definition['field'], 'filters') ?? '',
            $this->operator($definition['operator'] ?? FilterOperator::Equals->value),
            $definition['value'] ?? null,
        );
    }

    private function operator(mixed $operator): FilterOperator
    {
        if ($operator instanceof FilterOperator) {
            return $operator;
        }

        $name = is_string($operator) ? $operator : get_debug_type($operator);

        return FilterOperator::tryFrom(is_string($operator) ? $operator : '')
            ?? throw $this->rejected("“{$name}” is not a filter operator.", 'filters');
    }

    /**
     * Accepts `'title asc'`, `['title' => 'asc']` or sort objects, matching how Craft's own element
     * queries are ordered.
     *
     * @return SearchSort[]
     */
    private function normalizeSorts(mixed $orderBy): array
    {
        if ($orderBy instanceof SearchSort) {
            return [$orderBy];
        }

        if (is_string($orderBy)) {
            $orderBy = array_filter(array_map('trim', explode(',', $orderBy)));
        }

        if ($orderBy === []) {
            throw $this->rejected('Sorting must name at least one field.', 'sorts');
        }

        if (!is_array($orderBy)) {
            throw $this->rejected('Sorting must be given as a string, an array or sort objects.', 'sorts');
        }

        $sorts = [];

        foreach ($orderBy as $field => $direction) {
            if ($direction instanceof SearchSort) {
                $sorts[] = $direction;
                continue;
            }

            if (is_int($field)) {
                [$field, $direction] = array_pad(explode(' ', trim($this->text($direction, 'sorts') ?? ''), 2), 2, null);
            }

            $sorts[] = SearchSort::make($this->text($field, 'sorts') ?? '', $this->direction($direction));
        }

        return $sorts;
    }

    private function direction(mixed $direction): SortDirection
    {
        if ($direction instanceof SortDirection) {
            return $direction;
        }

        if ($direction === null || $direction === '') {
            return SortDirection::Asc;
        }

        if (!is_string($direction)) {
            throw $this->rejected('A sort direction must be “asc” or “desc”.', 'sorts');
        }

        return SortDirection::tryFrom(mb_strtolower($direction))
            ?? throw $this->rejected('A sort direction must be “asc” or “desc”.', 'sorts');
    }
}
