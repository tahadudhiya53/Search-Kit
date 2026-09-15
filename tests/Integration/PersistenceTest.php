<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * Round-trips SearchKit's configuration through the real database.
 */
class PersistenceTest extends IntegrationTestCase
{
    public function testIndexRoundTripsThroughTheDatabase(): void
    {
        $index = $this->persistIndex(new SearchIndex([
            'name' => 'Round Trip',
            'handle' => $this->uniqueHandle(),
            'provider' => CraftProvider::class,
            'enabled' => false,
            'settings' => ['pageSize' => 25, 'nested' => ['a' => 1]],
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        ]));

        self::assertNotNull($index->id);
        self::assertNotNull($index->uid);

        // Read back through a fresh service so nothing is served from memory.
        $read = $this->freshIndexes()->getIndexByHandle($index->handle);

        self::assertNotNull($read);
        self::assertSame($index->id, $read->id);
        self::assertSame('Round Trip', $read->name);
        self::assertSame(CraftProvider::class, $read->provider);
        self::assertFalse($read->enabled);
        self::assertSame(['pageSize' => 25, 'nested' => ['a' => 1]], $read->settings);
        self::assertSame(Craft::$app->getSites()->getPrimarySite()->id, $read->siteId);
        self::assertSame($index->uid, $read->uid);
    }

    public function testAnAllSiteIndexStoresANullSite(): void
    {
        $index = $this->persistIndex($this->newIndex());

        self::assertNull($this->freshIndexes()->getIndexByHandle($index->handle)?->siteId);
        self::assertTrue($index->coversAllSites());
    }

    public function testDuplicateHandleIsRejected(): void
    {
        $first = $this->persistIndex($this->newIndex());

        $duplicate = $this->newIndex();
        $duplicate->handle = $first->handle;

        self::assertFalse($this->plugin()->getIndexes()->saveIndex($duplicate));
        self::assertArrayHasKey('handle', $duplicate->getErrors());
        self::assertNull($duplicate->id);
    }

    public function testSearchableFieldRoundTripsThroughTheDatabase(): void
    {
        $index = $this->persistIndex($this->newIndex());

        $field = new SearchableField([
            'indexId' => $index->id,
            'elementType' => Entry::class,
            'handle' => 'title',
            'weight' => 10,
        ]);

        self::assertTrue($this->plugin()->getSearchableFields()->saveField($field));
        self::assertNotNull($field->id);

        $read = $this->plugin()->getSearchableFields()->getFieldsByIndexId($index->id);

        self::assertCount(1, $read);
        self::assertSame(Entry::class, $read[0]->elementType);
        self::assertSame('title', $read[0]->handle);
        self::assertSame(10, $read[0]->weight);
        self::assertTrue($read[0]->enabled);
    }

    public function testFieldsAreOrderedByWeight(): void
    {
        $index = $this->persistIndex($this->newIndex());

        foreach (['body' => 2, 'title' => 10, 'summary' => 5] as $handle => $weight) {
            $this->saveField($index->id, $handle, $weight);
        }

        $weights = $this->plugin()->getSearchableFields()->getFieldsByIndexId($index->id);

        self::assertSame(['title', 'summary', 'body'], array_map(static fn($f) => $f->handle, $weights));
    }

    public function testDeletingAnIndexCascadesToItsFields(): void
    {
        $index = $this->persistIndex($this->newIndex());
        $this->saveField($index->id, 'title', 10);

        self::assertSame(1, $this->fieldRowCount($index->id));

        self::assertTrue($this->plugin()->getIndexes()->deleteIndex($index));

        self::assertSame(0, $this->fieldRowCount($index->id));
        self::assertNull($this->freshIndexes()->getIndexByHandle($index->handle));
    }

    public function testAFieldForAMissingIndexFailsValidationRatherThanTheForeignKey(): void
    {
        $field = new SearchableField([
            'indexId' => 2147483600,
            'elementType' => Entry::class,
            'handle' => 'title',
        ]);

        self::assertFalse($this->plugin()->getSearchableFields()->saveField($field));
        self::assertArrayHasKey('indexId', $field->getErrors());
    }

    public function testAnIndexScopedToAMissingSiteFailsCleanly(): void
    {
        $index = $this->newIndex();
        $index->siteId = 2147483600;

        self::assertFalse($this->plugin()->getIndexes()->saveIndex($index));
        self::assertArrayHasKey('siteId', $index->getErrors());
        self::assertSame(0, (int)(new Query())->from([Table::INDEXES])->where(['handle' => $index->handle])->count());
    }

    private function saveField(int $indexId, string $handle, int $weight): void
    {
        $field = new SearchableField([
            'indexId' => $indexId,
            'elementType' => Entry::class,
            'handle' => $handle,
            'weight' => $weight,
        ]);

        self::assertTrue($this->plugin()->getSearchableFields()->saveField($field), implode(' ', $field->getErrorSummary(true)));
    }

    private function fieldRowCount(int $indexId): int
    {
        return (int)(new Query())
            ->from([Table::SEARCHABLEFIELDS])
            ->where(['indexId' => $indexId])
            ->count();
    }
}
