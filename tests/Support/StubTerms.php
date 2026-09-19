<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use Tahadudhiya\SearchKit\services\Terms;

/**
 * The words an index holds, kept in memory. Deciding what is publicly searchable needs real
 * elements, so every word here counts as visible unless a test says otherwise.
 */
class StubTerms extends Terms
{
    /** @var string[] */
    public array $words = [];

    /** @var string[] Words no publicly searchable document uses. */
    public array $hidden = [];

    public function startingWith(int $indexId, ?int $siteId, string $prefix, int $limit, int $offset = 0): array
    {
        $matches = $this->ordered(
            array_filter($this->words, static fn(string $word) => str_starts_with($word, $prefix)),
        );

        return array_slice($matches, $offset, $limit);
    }

    public function withinLengthOf(int $indexId, ?int $siteId, string $term, int $maxDistance): array
    {
        $length = mb_strlen($term);

        return $this->ordered(array_filter(
            $this->words,
            static fn(string $word) => abs(mb_strlen($word) - $length) <= $maxDistance,
        ));
    }

    public function visible(int $indexId, ?int $siteId, array $terms): array
    {
        return array_values(array_filter(
            $terms,
            fn(string $term) => in_array($term, $this->words, true) && !in_array($term, $this->hidden, true),
        ));
    }

    public function isSearchable(int $indexId, ?int $siteId, string $term): bool
    {
        return $this->visible($indexId, $siteId, [$term]) !== [];
    }

    /**
     * @param string[] $words
     * @return string[]
     */
    private function ordered(array $words): array
    {
        $words = array_values(array_unique($words));
        usort($words, static fn(string $a, string $b) => [mb_strlen($a), $a] <=> [mb_strlen($b), $b]);

        return $words;
    }
}
