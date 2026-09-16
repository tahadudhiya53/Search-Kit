<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\enums\FilterOperator;
use Tahadudhiya\SearchKit\enums\SortDirection;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\models\SearchQuery;

/**
 * Covers the plain-value entry point templates use, which is where untrusted input is vetted.
 */
class SearchQueryParamsTest extends TestCase
{
    public function testBuildsAQueryFromPlainParameters(): void
    {
        $query = SearchQuery::create('siteSearch', 'winter boots', [
            'limit' => 5,
            'page' => 3,
            'status' => 'live',
            'highlight' => true,
            'snippetLength' => 80,
        ]);

        self::assertSame('winter boots', $query->getNormalizedText());
        self::assertSame('siteSearch', $query->indexHandle);
        self::assertSame(5, $query->limit);
        self::assertSame(10, $query->offset);
        self::assertSame(3, $query->getPage());
        self::assertSame('live', $query->status);
        self::assertTrue($query->highlight);
        self::assertSame(80, $query->snippetLength);
        self::assertTrue($query->validate());
    }

    public function testRejectsAnUnknownParameter(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('is not a search parameter');
        SearchQuery::create('siteSearch', 'boots', ['sections' => ['news']]);
    }

    public function testAPlainValueFilterMeansEquals(): void
    {
        $filters = SearchQuery::create('siteSearch', 'boots', ['filters' => ['section' => 'news']])->getFilters();

        self::assertCount(1, $filters);
        self::assertSame('section', $filters[0]->field);
        self::assertSame(FilterOperator::Equals, $filters[0]->operator);
        self::assertSame('news', $filters[0]->value);
    }

    public function testAListFilterMeansAnyOfThem(): void
    {
        $filters = SearchQuery::create('siteSearch', 'boots', [
            'filters' => ['section' => ['news', 'blog']],
        ])->getFilters();

        self::assertSame(FilterOperator::In, $filters[0]->operator);
        self::assertSame(['news', 'blog'], $filters[0]->value);
    }

    public function testFiltersCanNameAnOperator(): void
    {
        $filters = SearchQuery::create('siteSearch', 'boots', [
            'filters' => [
                'postDate' => ['gte' => '2024-01-01'],
                'section' => ['neq' => 'news'],
            ],
        ])->getFilters();

        self::assertSame(FilterOperator::GreaterThanOrEquals, $filters[0]->operator);
        self::assertSame('2024-01-01', $filters[0]->value);
        self::assertSame(FilterOperator::NotEquals, $filters[1]->operator);
    }

    public function testFiltersCanBeGivenAsDefinitions(): void
    {
        $filters = SearchQuery::create('siteSearch', 'boots', [
            'filters' => [['field' => 'sectionId', 'operator' => 'in', 'value' => [1, 2]]],
        ])->getFilters();

        self::assertSame('sectionId', $filters[0]->field);
        self::assertSame(FilterOperator::In, $filters[0]->operator);
    }

    public function testRejectsAnUnknownFilterOperator(): void
    {
        $this->expectException(InvalidQueryException::class);
        SearchQuery::create('siteSearch', 'boots', ['filters' => ['postDate' => ['between' => 1]]]);
    }

    public function testRejectsAFilterValueThatCannotBeCompared(): void
    {
        $query = SearchQuery::create('siteSearch', 'boots', [
            'filters' => ['section' => ['eq' => new \stdClass()]],
        ]);

        self::assertFalse($query->validate());
        self::assertArrayHasKey('filters', $query->getErrors());
    }

    public function testSortingIsAcceptedAsAString(): void
    {
        $sorts = SearchQuery::create('siteSearch', 'boots', ['orderBy' => 'title asc, score desc'])->getSorts();

        self::assertCount(2, $sorts);
        self::assertSame('title', $sorts[0]->field);
        self::assertSame(SortDirection::Asc, $sorts[0]->direction);
        self::assertSame('score', $sorts[1]->field);
        self::assertSame(SortDirection::Desc, $sorts[1]->direction);
    }

    public function testSortingIsAcceptedAsAMap(): void
    {
        $sorts = SearchQuery::create('siteSearch', 'boots', ['orderBy' => ['postDate' => 'desc']])->getSorts();

        self::assertSame('postDate', $sorts[0]->field);
        self::assertSame(SortDirection::Desc, $sorts[0]->direction);
        self::assertTrue(SearchQuery::create('siteSearch', 'b', ['orderBy' => 'title'])->hasCustomSort());
    }

    public function testRejectsAnUnknownSortDirection(): void
    {
        $this->expectException(InvalidQueryException::class);
        SearchQuery::create('siteSearch', 'boots', ['orderBy' => 'title sideways']);
    }

    public function testASnippetLengthOutsideTheAllowedRangeIsRejected(): void
    {
        $query = SearchQuery::create('siteSearch', 'boots', [
            'snippetLength' => SearchQuery::MAX_SNIPPET_LENGTH + 1,
        ]);

        self::assertFalse($query->validate());
        self::assertArrayHasKey('snippetLength', $query->getErrors());
    }

    /**
     * @return iterable<string,array{mixed}>
     */
    public static function nonIntegers(): iterable
    {
        yield 'a fraction' => [20.5];
        yield 'a fraction as text' => ['20.5'];
        yield 'words' => ['soon'];
        yield 'empty text' => [''];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'null' => [null];
        yield 'a list' => [[20]];
        yield 'an object' => [new \stdClass()];
    }

    #[DataProvider('nonIntegers')]
    public function testWholeNumberParametersRejectAnythingElse(mixed $value): void
    {
        foreach (['limit', 'offset', 'page', 'snippetLength'] as $name) {
            try {
                SearchQuery::create('siteSearch', 'boots', [$name => $value]);
                self::fail("“{$name}” should not accept " . get_debug_type($value) . '.');
            } catch (InvalidQueryException $e) {
                self::assertArrayHasKey($name, $e->getErrors());
            }
        }
    }

    public function testWholeNumbersAreAcceptedAsNumbersOrAsRequestParameters(): void
    {
        // Request parameters arrive as strings, which is the shape a template passes straight in.
        $query = SearchQuery::create('siteSearch', 'boots', ['limit' => '5', 'page' => '3']);

        self::assertSame(5, $query->limit);
        self::assertSame(10, $query->offset);
        self::assertSame(20, SearchQuery::create('siteSearch', 'b', ['limit' => 20.0])->limit);
    }

    public function testAPageIsResolvedAgainstTheLimitWhicheverOrderTheyAreWrittenIn(): void
    {
        $limitFirst = SearchQuery::create('siteSearch', 'boots', ['limit' => 50, 'page' => 2]);
        $pageFirst = SearchQuery::create('siteSearch', 'boots', ['page' => 2, 'limit' => 50]);

        foreach ([$limitFirst, $pageFirst] as $query) {
            self::assertSame(50, $query->limit);
            self::assertSame(50, $query->offset);
            self::assertSame(2, $query->getPage());
        }
    }

    public function testBoundaryValuesAreKeptForValidationRatherThanCoerced(): void
    {
        $query = SearchQuery::create('siteSearch', 'boots', ['limit' => SearchQuery::MAX_LIMIT, 'offset' => 0]);

        self::assertTrue($query->validate());
        self::assertFalse(SearchQuery::create('siteSearch', 'b', ['limit' => 0])->validate());
        self::assertFalse(SearchQuery::create('siteSearch', 'b', ['offset' => -1])->validate());
        self::assertFalse(SearchQuery::create('siteSearch', 'b', ['snippetLength' => 0])->validate());
    }

    /**
     * @return iterable<string,array{mixed}>
     */
    public static function nonBooleans(): iterable
    {
        yield 'the word false' => ['false'];
        yield 'the word true' => ['true'];
        yield 'one' => [1];
        yield 'zero' => [0];
        yield 'empty text' => [''];
        yield 'null' => [null];
        yield 'a list' => [[true]];
    }

    #[DataProvider('nonBooleans')]
    public function testHighlightOnlyAcceptsARealBoolean(mixed $value): void
    {
        try {
            SearchQuery::create('siteSearch', 'boots', ['highlight' => $value]);
            self::fail('“highlight” should not accept ' . get_debug_type($value) . '.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('highlight', $e->getErrors());
        }
    }

    public function testHighlightAcceptsBothBooleans(): void
    {
        self::assertTrue(SearchQuery::create('siteSearch', 'b', ['highlight' => true])->highlight);
        self::assertFalse(SearchQuery::create('siteSearch', 'b', ['highlight' => false])->highlight);
    }

    /**
     * @return iterable<string,array{mixed}>
     */
    public static function nonStrings(): iterable
    {
        yield 'a number' => [5];
        yield 'true' => [true];
        yield 'a list' => [['live']];
        yield 'an object' => [new \stdClass()];
        yield 'empty text' => [''];
        yield 'whitespace' => ['   '];
    }

    #[DataProvider('nonStrings')]
    public function testStatusOnlyAcceptsText(mixed $value): void
    {
        try {
            SearchQuery::create('siteSearch', 'boots', ['status' => $value]);
            self::fail('“status” should not accept ' . get_debug_type($value) . '.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('status', $e->getErrors());
        }
    }

    public function testStatusAcceptsTextOrNothing(): void
    {
        self::assertSame('live', SearchQuery::create('siteSearch', 'b', ['status' => ' live '])->status);
        self::assertNull(SearchQuery::create('siteSearch', 'b', ['status' => null])->status);
    }

    public function testASiteMustBeAHandleAnIdOrASiteModel(): void
    {
        self::assertSame(3, SearchQuery::create('siteSearch', 'b', ['site' => 3])->siteId);
        self::assertSame(3, SearchQuery::create('siteSearch', 'b', ['site' => '3'])->siteId);
        self::assertNull(SearchQuery::create('siteSearch', 'b', ['site' => null])->siteId);

        foreach ([true, 2.5, ['default'], new \stdClass(), ''] as $value) {
            try {
                SearchQuery::create('siteSearch', 'b', ['site' => $value]);
                self::fail('“site” should not accept ' . get_debug_type($value) . '.');
            } catch (InvalidQueryException $e) {
                self::assertArrayHasKey('siteId', $e->getErrors());
            }
        }
    }

    public function testFilterAndSortFieldsMustBeNamed(): void
    {
        foreach ([
            ['filters' => [['field' => 5, 'value' => 'x']]],
            ['filters' => [['field' => '', 'value' => 'x']]],
            ['orderBy' => [['title']]],
            ['orderBy' => ''],
            ['orderBy' => []],
        ] as $params) {
            $this->expectRejection($params);
        }
    }

    public function testRefusalsSayNothingAboutTheImplementation(): void
    {
        foreach ([['limit' => 'soon'], ['highlight' => 'false'], ['status' => 5], ['nonsense' => 1]] as $params) {
            try {
                SearchQuery::create('siteSearch', 'boots', $params);
                self::fail('This parameter should have been refused.');
            } catch (InvalidQueryException $e) {
                foreach (['SELECT', 'SQLSTATE', '/var/', '.php', 'Tahadudhiya'] as $fragment) {
                    self::assertStringNotContainsString($fragment, $e->getMessage());
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $params
     */
    private function expectRejection(array $params): void
    {
        try {
            SearchQuery::create('siteSearch', 'boots', $params);
            self::fail('These parameters should have been refused: ' . json_encode(array_keys($params)));
        } catch (InvalidQueryException $e) {
            self::assertNotSame([], $e->getErrors());
        }
    }

    public function testTokensAreTheTermsAMatchCanBeExplainedBy(): void
    {
        $query = SearchQuery::create('siteSearch', 'Winter  BOOTS, winter!');

        self::assertSame(['winter', 'boots'], $query->getTokens());
    }
}
