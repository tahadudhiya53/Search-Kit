<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\enums\FilterOperator;
use Tahadudhiya\SearchKit\models\SearchFilter;

/**
 * A filter is the only place untrusted values are held before a provider translates them, so what
 * it accepts decides what a provider can be asked to do.
 */
class SearchFilterTest extends TestCase
{
    public function testAcceptsTheValuesEveryProviderCanCompare(): void
    {
        self::assertTrue(SearchFilter::make('section', FilterOperator::Equals, 'news')->validate());
        self::assertTrue(SearchFilter::make('id', FilterOperator::Equals, 7)->validate());
        self::assertTrue(SearchFilter::make('featured', FilterOperator::Equals, true)->validate());
        self::assertTrue(SearchFilter::make('postDate', FilterOperator::GreaterThan, new DateTime())->validate());
        self::assertTrue(SearchFilter::make('section', FilterOperator::In, ['news', 'blog'])->validate());
    }

    public function testRejectsAValueNoProviderCouldCompare(): void
    {
        $filter = SearchFilter::make('section', FilterOperator::Equals, new \stdClass());

        self::assertFalse($filter->validate());
        self::assertArrayHasKey('value', $filter->getErrors());
    }

    public function testRejectsANullValue(): void
    {
        // Craft ignores a null parameter, which would silently answer a different question.
        self::assertFalse(SearchFilter::make('section', FilterOperator::Equals, null)->validate());
        self::assertFalse(SearchFilter::make('section', FilterOperator::In, [null])->validate());
    }

    public function testARangeNeedsABottomAndATop(): void
    {
        self::assertTrue(SearchFilter::make('price', FilterOperator::Between, [100, 500])->validate());
        self::assertTrue(SearchFilter::make('postDate', FilterOperator::Between, [
            new DateTime('-1 week'),
            new DateTime(),
        ])->validate());

        // One bound is half a range, and three is not one at all.
        self::assertFalse(SearchFilter::make('price', FilterOperator::Between, [100])->validate());
        self::assertFalse(SearchFilter::make('price', FilterOperator::Between, [100, 500, 900])->validate());
        self::assertFalse(SearchFilter::make('price', FilterOperator::Between, 100)->validate());
        self::assertFalse(SearchFilter::make('price', FilterOperator::Between, [true, false])->validate());
    }

    public function testARangeRunningBackwardsIsRejected(): void
    {
        // A bottom above its top matches nothing, which no provider would report as a mistake.
        self::assertFalse(SearchFilter::make('price', FilterOperator::Between, [500, 100])->validate());
        self::assertFalse(SearchFilter::make('price', FilterOperator::Between, ['500', '100.5'])->validate());
        self::assertFalse(SearchFilter::make('postDate', FilterOperator::Between, [
            new DateTime('2024-06-01'),
            new DateTime('2024-01-01'),
        ])->validate());
        self::assertFalse(SearchFilter::make('postDate', FilterOperator::Between, ['2024-06-01', '2024-01-01'])->validate());

        // The bounds are inclusive, so a range of one value is a range.
        self::assertTrue(SearchFilter::make('price', FilterOperator::Between, [100, 100])->validate());
        self::assertTrue(SearchFilter::make('postDate', FilterOperator::Between, ['2024-01-01', '2024-06-01'])->validate());

        // Text with no ordering every provider agrees on is left to the provider to judge.
        self::assertTrue(SearchFilter::make('slug', FilterOperator::Between, ['zebra', 'apple'])->validate());
    }

    public function testRejectsAListOperatorWithoutAList(): void
    {
        self::assertFalse(SearchFilter::make('section', FilterOperator::In, 'news')->validate());
        self::assertFalse(SearchFilter::make('section', FilterOperator::NotIn, 'news')->validate());
    }

    public function testRejectsAnEmptyList(): void
    {
        self::assertFalse(SearchFilter::make('section', FilterOperator::In, [])->validate());
        self::assertFalse(SearchFilter::make('section', FilterOperator::NotIn, [])->validate());
    }

    public function testRejectsASingleValueOperatorGivenAList(): void
    {
        self::assertFalse(SearchFilter::make('section', FilterOperator::Equals, ['a', 'b'])->validate());
        self::assertFalse(SearchFilter::make('postDate', FilterOperator::GreaterThan, ['a'])->validate());
    }

    public function testRejectsComparingSomethingWithNoOrder(): void
    {
        foreach ([FilterOperator::GreaterThan, FilterOperator::GreaterThanOrEquals, FilterOperator::LessThan, FilterOperator::LessThanOrEquals] as $operator) {
            self::assertTrue($operator->isComparison());
            self::assertFalse(SearchFilter::make('featured', $operator, true)->validate());
            self::assertTrue(SearchFilter::make('price', $operator, 10)->validate());
        }
    }

    public function testRequiresAField(): void
    {
        self::assertFalse(SearchFilter::make('', FilterOperator::Equals, 'news')->validate());
    }
}
