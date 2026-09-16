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
        $query = SearchQuery::create('siteSearch', "  red   winter\n coat ");

        self::assertSame('red winter coat', $query->getNormalizedText());
    }

    public function testValidQueryPasses(): void
    {
        self::assertTrue(SearchQuery::create('siteSearch', 'boots')->validate());
    }

    public function testRejectsWhitespaceOnlyText(): void
    {
        $query = SearchQuery::create('siteSearch', "   \t ");

        self::assertFalse($query->validate());
        self::assertArrayHasKey('text', $query->getErrors());
    }

    public function testRejectsInvalidIndexHandle(): void
    {
        $query = SearchQuery::create('2 bad handles', 'boots');

        self::assertFalse($query->validate());
        self::assertArrayHasKey('indexHandle', $query->getErrors());
    }

    public function testValidatesFiltersAlongsideTheQuery(): void
    {
        $query = SearchQuery::create('siteSearch', 'boots')
            ->addFilter(SearchFilter::make('sectionId', FilterOperator::In, 5));

        self::assertFalse($query->validate());
        self::assertArrayHasKey('filters', $query->getErrors());
    }

    public function testRejectsADirectiveOfTheWrongType(): void
    {
        $query = SearchQuery::create('siteSearch', 'boots');

        $this->expectException(InvalidArgumentException::class);
        $query->setFilters([SearchSort::make('postDate')]);
    }

    public function testPageMapsToOffset(): void
    {
        $query = SearchQuery::create('siteSearch', 'boots');
        $query->limit = 20;

        self::assertSame(60, $query->setPage(4)->offset);
        self::assertSame(4, $query->getPage());
    }

    public function testOnlyNonScoreSortsCountAsCustom(): void
    {
        $query = SearchQuery::create('siteSearch', 'boots')->addSort('score');

        self::assertFalse($query->hasCustomSort());

        $query->addSort('postDate', SortDirection::Asc);

        self::assertTrue($query->hasCustomSort());
    }
}
