<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use craft\validators\HandleValidator;
use InvalidArgumentException;
use Tahadudhiya\SearchKit\enums\SortDirection;

/**
 * A search request in SearchKit's own terms. It never carries provider request structures.
 */
class SearchQuery extends Model
{
    public const MAX_LIMIT = 1000;

    public string $text = '';
    public string $indexHandle = '';

    /** @var int|null Restricts the search to one site, or null to search the index's whole scope. */
    public ?int $siteId = null;
    public ?string $status = null;
    public int $limit = 20;
    public int $offset = 0;

    /** @var SearchFilter[] */
    private array $_filters = [];

    /** @var SearchSort[] */
    private array $_sorts = [];

    public static function make(string $text, string $indexHandle): self
    {
        return new self([
            'text' => $text,
            'indexHandle' => $indexHandle,
        ]);
    }

    /**
     * Trimmed, whitespace-collapsed query text — the form providers and the debugger both work from.
     */
    public function getNormalizedText(): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $this->text));
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
            [['text'], 'validateText'],
            [['limit'], 'integer', 'min' => 1, 'max' => self::MAX_LIMIT],
            [['offset'], 'integer', 'min' => 0],
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
}
