<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\elements\Asset;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;

/**
 * An index spanning several element types has to paginate as one merged result set.
 */
class MixedElementTypeTest extends IntegrationTestCase
{
    private const TERM = 'home OR image';

    public function testTotalIsTheSumOfEveryElementType(): void
    {
        $result = $this->search($this->mixedIndex(), 20, 0);

        self::assertSame($this->expectedTotal(), $result->total);
        self::assertEqualsCanonicalizing([Entry::class, Asset::class], $result->metadata['elementTypes']);
    }

    public function testBothElementTypesReachTheFirstPage(): void
    {
        $index = $this->mixedIndex();

        $types = array_unique(array_map(
            static fn(SearchHit $hit) => $hit->elementType,
            $this->search($index, 50, 0)->hits,
        ));

        self::assertEqualsCanonicalizing([Entry::class, Asset::class], array_values($types));
    }

    public function testPagingWalksTheWholeResultSetWithoutGapsOrRepeats(): void
    {
        $index = $this->mixedIndex();
        $total = $this->expectedTotal();
        $limit = 3;

        $seen = [];
        $scores = [];

        for ($offset = 0; $offset < $total; $offset += $limit) {
            $result = $this->search($index, $limit, $offset);

            self::assertSame($total, $result->total, 'The total must not drift between pages.');
            self::assertLessThanOrEqual($limit, $result->getCount());

            foreach ($result->hits as $hit) {
                $seen[] = "$hit->elementType:$hit->elementId:$hit->siteId";
                $scores[] = $hit->score;
            }
        }

        self::assertCount($total, $seen, 'Paging should reach every match exactly once.');
        self::assertSame($seen, array_values(array_unique($seen)), 'No match should appear on two pages.');

        $sorted = $scores;
        rsort($sorted);
        self::assertSame($sorted, $scores, 'The merged ordering must stay highest-score-first across pages.');
    }

    public function testAPageMatchesTheSameSliceOfTheFullResultSet(): void
    {
        $index = $this->mixedIndex();

        $all = $this->keys($this->search($index, 50, 0));
        $secondPage = $this->keys($this->search($index, 2, 2));

        self::assertSame(array_slice($all, 2, 2), $secondPage);
    }

    /**
     * @return string[]
     */
    private function keys(\Tahadudhiya\SearchKit\models\SearchResult $result): array
    {
        return array_map(
            static fn(SearchHit $hit) => "$hit->elementType:$hit->elementId:$hit->siteId",
            $result->hits,
        );
    }

    private function expectedTotal(): int
    {
        return (int)Entry::find()->search(self::TERM)->siteId('*')->count()
            + (int)Asset::find()->search(self::TERM)->siteId('*')->count();
    }

    private function search(SearchIndex $index, int $limit, int $offset): \Tahadudhiya\SearchKit\models\SearchResult
    {
        $query = SearchQuery::make(self::TERM, $index->handle);
        $query->limit = $limit;
        $query->offset = $offset;

        return $this->plugin()->getSearch()->search($query);
    }

    private function mixedIndex(): SearchIndex
    {
        $index = $this->persistIndex($this->newIndex());

        foreach ([Entry::class, Asset::class] as $elementType) {
            $field = new SearchableField([
                'indexId' => $index->id,
                'elementType' => $elementType,
                'handle' => 'title',
                'weight' => 5,
            ]);

            self::assertTrue($this->plugin()->getSearchableFields()->saveField($field));
        }

        if ($this->expectedTotal() < 4) {
            self::markTestSkipped('This project needs more matching content across element types.');
        }

        return $index;
    }
}
