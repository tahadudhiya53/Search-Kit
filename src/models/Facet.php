<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * How a whole result set divides up by one field: every value it holds and how many results carry
 * it. Counts describe the search rather than the page, so a filter can be offered from them.
 */
class Facet extends Model
{
    public string $field = '';

    /** @var array<int,array{value:string,count:int}> Commonest value first, then alphabetically. */
    private array $_values = [];

    /**
     * @param array<string|int,int|string> $counts Value => how many results carry it.
     */
    public static function make(string $field, array $counts): self
    {
        $facet = new self(['field' => $field]);
        $facet->setCounts($counts);

        return $facet;
    }

    /**
     * @param array<string|int,int|string> $counts
     */
    public function setCounts(array $counts): void
    {
        $values = [];

        foreach ($counts as $value => $count) {
            if ((int)$count > 0) {
                $values[] = ['value' => (string)$value, 'count' => (int)$count];
            }
        }

        // Ordered here rather than by the provider, so two providers present one facet the same way.
        usort($values, static fn(array $a, array $b) => [$b['count'], $a['value']] <=> [$a['count'], $b['value']]);

        $this->_values = $values;
    }

    /**
     * @return array<int,array{value:string,count:int}>
     */
    public function getValues(): array
    {
        return $this->_values;
    }

    /**
     * @return array<string,int>
     */
    public function getCounts(): array
    {
        return array_column($this->_values, 'count', 'value');
    }

    public function countFor(string|int $value): int
    {
        return $this->getCounts()[(string)$value] ?? 0;
    }

    public function isEmpty(): bool
    {
        return $this->_values === [];
    }
}
