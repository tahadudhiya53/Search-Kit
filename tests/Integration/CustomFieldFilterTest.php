<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\elements\Entry;
use Tahadudhiya\SearchKit\enums\FilterOperator;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchFilter;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * Filters on a real Craft custom field, which is the only way to prove SearchKit's filters reach
 * Craft's own field query conditions rather than being quietly ignored.
 */
class CustomFieldFilterTest extends SearchContentTestCase
{
    private const TERM = 'zqxwfiltrate';

    private SearchIndex $index;

    /** @var Entry[] Keyed by the value of the field under test. */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->fieldSectionSiteId(),
        );

        $this->addFieldToIndex(static::FIELD);

        foreach (['alpha', 'bravo', 'charlie'] as $value) {
            $this->entries[$value] = $this->createPage("Zqxwfiltrate $value", [static::FIELD => $value]);
        }
    }

    public function testEqualsNarrowsTheResults(): void
    {
        $unfiltered = $this->search();
        $filtered = $this->search(['filters' => [static::FIELD => 'bravo']]);

        self::assertSame(3, $unfiltered->total);
        self::assertSame(1, $filtered->total, 'The filter must actually change the result set.');
        self::assertSame([$this->entries['bravo']->id], $this->idsOf($filtered));
    }

    public function testNotEqualsExcludesTheValue(): void
    {
        $result = $this->search(['filters' => [static::FIELD => ['neq' => 'bravo']]]);

        self::assertSame(2, $result->total);
        self::assertNotContains($this->entries['bravo']->id, $this->idsOf($result));
    }

    public function testInMatchesAnyOfTheValues(): void
    {
        $result = $this->search(['filters' => [static::FIELD => ['alpha', 'charlie']]]);

        self::assertSame(2, $result->total);
        self::assertEqualsCanonicalizing(
            [$this->entries['alpha']->id, $this->entries['charlie']->id],
            $this->idsOf($result),
        );
    }

    public function testNotInExcludesEveryListedValue(): void
    {
        $result = $this->search(['filters' => [static::FIELD => ['notIn' => ['alpha', 'charlie']]]]);

        self::assertSame(1, $result->total);
        self::assertSame([$this->entries['bravo']->id], $this->idsOf($result));
    }

    public function testComparisonOperatorsOrderValues(): void
    {
        self::assertSame(2, $this->search(['filters' => [static::FIELD => ['gt' => 'alpha']]])->total);
        self::assertSame(3, $this->search(['filters' => [static::FIELD => ['gte' => 'alpha']]])->total);
        self::assertSame(1, $this->search(['filters' => [static::FIELD => ['lt' => 'bravo']]])->total);
        self::assertSame(2, $this->search(['filters' => [static::FIELD => ['lte' => 'bravo']]])->total);
    }

    public function testFiltersCanBeBuiltAsObjectsToo(): void
    {
        $query = SearchQuery::create($this->index->handle, self::TERM)
            ->addFilter(SearchFilter::make(static::FIELD, FilterOperator::Equals, 'charlie'));

        self::assertSame([$this->entries['charlie']->id], $this->idsOf($this->plugin()->getSearch()->search($query)));
    }

    public function testAFieldTheIndexDoesNotSearchCannotBeFilteredOn(): void
    {
        // The field exists on the element type, but this index is not configured with it.
        $this->expectException(InvalidQueryException::class);
        $this->search(['filters' => ['searchKitTestDescription' => 'anything']]);
    }

    public function testAFieldCraftStoresNoQueryableValueForIsRejected(): void
    {
        $index = $this->newIndex();
        $index->siteId = $this->fieldSectionSiteId();
        $index->setFields([
            new SearchableField(['elementType' => Entry::class, 'handle' => 'title']),
            new SearchableField(['elementType' => Entry::class, 'handle' => $this->unqueryableFieldHandle()]),
        ]);

        $query = SearchQuery::create($index->handle, self::TERM)
            ->addFilter(SearchFilter::make($this->unqueryableFieldHandle(), FilterOperator::Equals, 'anything'));

        try {
            (new CraftProvider())->search($query, $index);
            self::fail('A field Craft cannot query should be rejected rather than matching nothing.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('filters', $e->getErrors());
        }
    }

    public function testAnEmptyListOfValuesIsRejected(): void
    {
        $query = SearchQuery::create($this->index->handle, self::TERM, [
            'filters' => [['field' => static::FIELD, 'operator' => 'in', 'value' => []]],
        ]);

        self::assertFalse($query->validate());
        self::assertArrayHasKey('filters', $query->getErrors());
    }

    private function unqueryableFieldHandle(): string
    {
        foreach (\Craft::$app->getFields()->getLayoutsByType(Entry::class) as $layout) {
            foreach ($layout->getCustomFields() as $field) {
                if ($field::dbType() === null) {
                    return $field->handle;
                }
            }
        }

        self::markTestSkipped('This project has no entry field Craft cannot query.');
    }

    private function addFieldToIndex(string $handle): void
    {
        $fields = $this->plugin()->getSearchableFields()->getFieldsByIndexId((int)$this->index->id);
        $fields[] = new SearchableField([
            'elementType' => Entry::class,
            'handle' => $handle,
            'weight' => 3,
        ]);

        self::assertTrue($this->plugin()->getSearchableFields()->saveFieldsForIndex($this->index, $fields));
    }

    /**
     * @param array<string,mixed> $params
     */
    private function search(array $params = []): \Tahadudhiya\SearchKit\models\SearchResult
    {
        return $this->searchIndex($this->index->handle, self::TERM, $params);
    }
}
