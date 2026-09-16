<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\enums\FilterOperator;
use Tahadudhiya\SearchKit\enums\SortDirection;
use Tahadudhiya\SearchKit\models\SearchFilter;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchSort;

class SearchQueryTest extends TestCase
{
    public function testNormalizesText(): void
    {
        $query = SearchQuery::make("  red   winter\n coat ", 'siteSearch');

        self::assertSame('red winter coat', $query->getNormalizedText());
    }

    public function testValidQueryPasses(): void
    {
        self::assertTrue(SearchQuery::make('boots', 'siteSearch')->validate());
    }

    public function testRejectsWhitespaceOnlyText(): void
    {
        $query = SearchQuery::make("   \t ", 'siteSearch');

        self::assertFalse($query->validate());
        self::assertArrayHasKey('text', $query->getErrors());
    }

    public function testRejectsInvalidIndexHandle(): void
    {
        $query = SearchQuery::make('boots', '2 bad handles');

        self::assertFalse($query->validate());
        self::assertArrayHasKey('indexHandle', $query->getErrors());
    }

    public function testRejectsOutOfRangePagination(): void
    {
        $query = SearchQuery::make('boots', 'siteSearch');
        $query->limit = SearchQuery::MAX_LIMIT + 1;
        $query->offset = -1;

        self::assertFalse($query->validate());
        self::assertArrayHasKey('limit', $query->getErrors());
        self::assertArrayHasKey('offset', $query->getErrors());
    }

    public function testValidatesFiltersAlongsideTheQuery(): void
    {
        $query = SearchQuery::make('boots', 'siteSearch')
            ->addFilter(SearchFilter::make('sectionId', FilterOperator::In, 5));

        self::assertFalse($query->validate());
        self::assertArrayHasKey('filters', $query->getErrors());
    }

    public function testRejectsADirectiveOfTheWrongType(): void
    {
        $query = SearchQuery::make('boots', 'siteSearch');

        $this->expectException(InvalidArgumentException::class);
        $query->setFilters([SearchSort::make('postDate')]);
    }

    public function testPageMapsToOffset(): void
    {
        $query = SearchQuery::make('boots', 'siteSearch');
        $query->limit = 20;

        self::assertSame(60, $query->setPage(4)->offset);
        self::assertSame(4, $query->getPage());
    }

    public function testOnlyNonScoreSortsCountAsCustom(): void
    {
        $query = SearchQuery::make('boots', 'siteSearch')->addSort('score');

        self::assertFalse($query->hasCustomSort());

        $query->addSort('postDate', SortDirection::Asc);

        self::assertTrue($query->hasCustomSort());
    }
}
