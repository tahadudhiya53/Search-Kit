<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\db\Query;
use craft\elements\Category;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\Tests\Support\RecordingProvider;

/**
 * An index and the fields it searches are one configuration: a save lands whole or not at all. And
 * once that configuration changes, what the provider holds can no longer be reported as current.
 */
class IndexConfigurationTest extends ContentTestCase
{
    public function testAValidConfigurationIsSavedWhole(): void
    {
        $index = $this->newIndex();

        self::assertTrue($this->save($index, [$this->field(Entry::class, 'title', 7)]));
        $this->registerForCleanup($index);

        $stored = $this->freshIndexes()->getIndexByHandle($index->handle);
        self::assertNotNull($stored);

        $fields = $this->plugin()->getSearchableFields()->getFieldsByIndexId((int)$stored->id);
        self::assertCount(1, $fields);
        self::assertSame('title', $fields[0]->handle);
        self::assertSame(7, $fields[0]->weight);
    }

    public function testANewIndexLeavesNothingBehindWhenItsFieldsAreInvalid(): void
    {
        $index = $this->newIndex();

        self::assertFalse($this->save($index, [$this->field(Entry::class, 'notAFieldAnywhere', 1)]));

        self::assertNull($index->id);
        self::assertSame(0, $this->indexRowCount($index->handle));
    }

    public function testAnExistingIndexKeepsItsPreviousConfigurationWhenFieldsAreInvalid(): void
    {
        $index = $this->newIndex();
        self::assertTrue($this->save($index, [$this->field(Entry::class, 'title', 5)]));
        $this->registerForCleanup($index);
        $this->markCurrent($index);

        // A change that would invalidate the index, posted alongside a field that cannot be saved.
        $index->provider = RecordingProvider::class;
        $index->siteId = 2147483600;

        self::assertFalse($this->save($index, [$this->field(Entry::class, 'notAFieldAnywhere', 1)]));

        $stored = $this->freshIndexes()->getIndexByHandle($index->handle);
        self::assertSame(CraftProvider::class, $stored->provider);
        self::assertNull($stored->siteId);
        self::assertFalse($stored->rebuildRequired, 'A rolled back save still marked the index stale.');

        $fields = $this->plugin()->getSearchableFields()->getFieldsByIndexId((int)$stored->id);
        self::assertCount(1, $fields);
        self::assertSame('title', $fields[0]->handle);
        self::assertSame(5, $fields[0]->weight);
    }

    public function testAnInvalidIndexLeavesTheFieldsAlone(): void
    {
        $index = $this->newIndex();
        self::assertTrue($this->save($index, [$this->field(Entry::class, 'title', 5)]));
        $this->registerForCleanup($index);

        // A site that does not exist fails the index itself, after the fields have validated.
        $index->siteId = 2147483600;

        self::assertFalse($this->save($index, [$this->field(Entry::class, 'slug', 9)]));

        $fields = $this->plugin()->getSearchableFields()->getFieldsByIndexId((int)$index->id);
        self::assertCount(1, $fields);
        self::assertSame('title', $fields[0]->handle);
    }

    public function testAnInvalidatingChangeAndItsFieldsCommitTogether(): void
    {
        $index = $this->newIndex();
        self::assertTrue($this->save($index, [$this->field(Entry::class, 'title', 5)]));
        $this->registerForCleanup($index);
        $this->markCurrent($index);

        $index->provider = RecordingProvider::class;
        self::assertTrue($this->save($index, [$this->field(Entry::class, 'slug', 3)]));

        $stored = $this->freshIndexes()->getIndexByHandle($index->handle);
        self::assertSame(RecordingProvider::class, $stored->provider);
        self::assertTrue($stored->rebuildRequired);

        $fields = $this->plugin()->getSearchableFields()->getFieldsByIndexId((int)$stored->id);
        self::assertCount(1, $fields);
        self::assertSame('slug', $fields[0]->handle);
    }

    public function testADuplicateFieldIsRejectedRatherThanSilentlyDropped(): void
    {
        $index = $this->newIndex();

        $saved = $this->save($index, [
            $this->field(Entry::class, 'title', 5),
            $this->field(Entry::class, 'title', 9),
        ]);

        self::assertFalse($saved);
        self::assertSame(0, $this->indexRowCount($index->handle));
    }

    public function testChangingSearchableFieldsInvalidatesTheIndex(): void
    {
        $index = $this->rebuiltIndex();

        $this->saveFields($index, [Entry::class => 'slug']);

        self::assertTrue($this->stored($index)->rebuildRequired);
    }

    public function testChangingAFieldWeightInvalidatesTheIndex(): void
    {
        $index = $this->rebuiltIndex();

        self::assertTrue($this->plugin()->getSearchableFields()->saveFieldsForIndex($index, [
            new SearchableField(['elementType' => Entry::class, 'handle' => 'title', 'weight' => 99]),
        ]));

        self::assertTrue($this->stored($index)->rebuildRequired);
    }

    public function testDisablingAFieldInvalidatesTheIndex(): void
    {
        $index = $this->rebuiltIndex();

        self::assertTrue($this->plugin()->getSearchableFields()->saveFieldsForIndex($index, [
            new SearchableField([
                'elementType' => Entry::class,
                'handle' => 'title',
                'weight' => 5,
                'enabled' => false,
            ]),
        ]));

        self::assertTrue($this->stored($index)->rebuildRequired);
    }

    public function testAddingAnElementTypeInvalidatesTheIndex(): void
    {
        $index = $this->rebuiltIndex();

        $this->saveFields($index, [Entry::class => 'title', Category::class => 'title']);

        self::assertTrue($this->stored($index)->rebuildRequired);
    }

    public function testSavingTheSameFieldConfigurationDoesNotInvalidateTheIndex(): void
    {
        $index = $this->rebuiltIndex();

        $this->saveFields($index, [Entry::class => 'title']);

        self::assertFalse($this->stored($index)->rebuildRequired);
    }

    public function testChangingTheSiteScopeInvalidatesTheIndex(): void
    {
        $index = $this->rebuiltIndex();

        $index->siteId = $this->sectionSiteId();
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));

        self::assertTrue($this->stored($index)->rebuildRequired);
    }

    public function testSavingTheSameSiteScopeDoesNotInvalidateTheIndex(): void
    {
        $index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            RecordingProvider::class,
            $this->sectionSiteId(),
        );
        $this->markCurrent($index);

        $index->name = 'Same scope, new name';
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));

        self::assertFalse($this->stored($index)->rebuildRequired);
    }

    public function testChangingTheProviderInvalidatesTheIndex(): void
    {
        $index = $this->rebuiltIndex();

        $index->provider = CraftProvider::class;
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));

        self::assertTrue($this->stored($index)->rebuildRequired);
    }

    public function testReEnablingAnIndexInvalidatesItBecauseChangesWereNotTrackedWhileItWasOff(): void
    {
        $index = $this->rebuiltIndex();

        $index->enabled = false;
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));
        self::assertFalse($this->stored($index)->rebuildRequired);

        $index->enabled = true;
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));

        self::assertTrue($this->stored($index)->rebuildRequired);
    }

    public function testRenamingAnIndexDoesNotInvalidateIt(): void
    {
        $index = $this->rebuiltIndex();

        $index->name = 'A different name';
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));

        self::assertFalse($this->stored($index)->rebuildRequired);
    }

    public function testAnInvalidatedIndexIsNeverReportedAsHealthy(): void
    {
        $index = $this->rebuiltIndex();
        $this->saveFields($index, [Entry::class => 'slug']);

        $status = $this->plugin()->getIndexing()->getStatus($this->stored($index));

        self::assertTrue($status->rebuildRequired);
        self::assertFalse($status->isHealthy());
    }

    public function testRebuildingAfterAConfigurationChangeMakesTheIndexCurrentAgain(): void
    {
        $index = $this->rebuiltIndex();
        $this->createEntry('SearchKit reconfigured content');
        $this->saveFields($index, [Entry::class => 'slug']);

        self::assertTrue($this->stored($index)->rebuildRequired);

        $result = $this->plugin()->getIndexing()->rebuild($this->stored($index));

        self::assertTrue($result->isComplete());

        $status = $this->plugin()->getIndexing()->getStatus($this->stored($index));
        self::assertFalse($status->rebuildRequired);
        self::assertTrue($status->isHealthy());
    }

    /**
     * An index that has just been rebuilt, so any staleness a test sees is the change it made.
     */
    private function rebuiltIndex(): SearchIndex
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title'], RecordingProvider::class);
        $this->markCurrent($index);

        return $index;
    }

    /**
     * @param SearchableField[] $fields
     */
    private function save(SearchIndex $index, array $fields): bool
    {
        return $this->plugin()->getIndexes()->saveIndexConfiguration($index, $fields);
    }

    private function field(string $elementType, string $handle, int $weight): SearchableField
    {
        return new SearchableField([
            'elementType' => $elementType,
            'handle' => $handle,
            'weight' => $weight,
        ]);
    }

    private function indexRowCount(string $handle): int
    {
        return (int)(new Query())->from([Table::INDEXES])->where(['handle' => $handle])->count();
    }

    /**
     * @param array<class-string,string> $fields
     */
    private function saveFields(SearchIndex $index, array $fields): void
    {
        $searchableFields = [];

        foreach ($fields as $elementType => $handle) {
            $searchableFields[] = new SearchableField([
                'elementType' => $elementType,
                'handle' => $handle,
                'weight' => 5,
            ]);
        }

        self::assertTrue($this->plugin()->getSearchableFields()->saveFieldsForIndex($index, $searchableFields));
    }

    private function stored(SearchIndex $index): SearchIndex
    {
        $stored = $this->freshIndexes()->getIndexByHandle($index->handle);
        self::assertNotNull($stored);
        $this->plugin()->getSearchableFields()->attachFields($stored);

        return $stored;
    }
}
