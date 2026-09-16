<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\elements\Category;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\Tests\Support\RecordingProvider;

/**
 * A provider holds what the configuration asked for. When that configuration changes, what it holds
 * can no longer be trusted, and the index has to say so rather than report itself as current.
 */
class IndexConfigurationTest extends ContentTestCase
{
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
