<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\db\Query;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\Tests\Support\RecordingProvider;

/**
 * An index and the fields it searches are one configuration. Saving half of it would leave the
 * provider holding content no configuration asked for, so a save either lands whole or not at all.
 */
class IndexConfigurationSaveTest extends IntegrationTestCase
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
}
