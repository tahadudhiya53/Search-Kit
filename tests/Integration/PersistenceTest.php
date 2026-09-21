<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\enums\PartialMatchMode;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\MeilisearchProvider;

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
            'provider' => MeilisearchProvider::class,
            'enabled' => false,
            // Settings a provider actually declares: anything else is refused at save, so what is
            // stored is always something the provider can be built from.
            'settings' => ['url' => 'http://localhost:7700', 'indexPrefix' => 'roundTrip'],
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        ]));

        self::assertNotNull($index->id);
        self::assertNotNull($index->uid);

        // Read back through a fresh service so nothing is served from memory.
        $read = $this->freshIndexes()->getIndexByHandle($index->handle);

        self::assertNotNull($read);
        self::assertSame($index->id, $read->id);
        self::assertSame('Round Trip', $read->name);
        self::assertSame(MeilisearchProvider::class, $read->provider);
        self::assertFalse($read->enabled);
        self::assertSame(['url' => 'http://localhost:7700', 'indexPrefix' => 'roundTrip'], $read->settings);
        self::assertSame(Craft::$app->getSites()->getPrimarySite()->id, $read->siteId);
        self::assertSame($index->uid, $read->uid);
    }

    public function testProviderSettingsAreValidatedBeforeTheyAreStored(): void
    {
        $undeclared = $this->newIndex(MeilisearchProvider::class);
        $undeclared->settings = ['url' => 'http://localhost:7700', 'pageSize' => 25];

        self::assertFalse($this->plugin()->getIndexes()->saveIndex($undeclared));
        self::assertNotSame([], $undeclared->getErrors('settings'));

        // A key typed in rather than named would be stored here and shown back to whoever can open
        // the index, so it is refused before it reaches the database.
        $literalKey = $this->newIndex(MeilisearchProvider::class);
        $literalKey->settings = ['url' => 'http://localhost:7700', 'apiKey' => 'a-real-looking-master-key'];

        self::assertFalse($this->plugin()->getIndexes()->saveIndex($literalKey));
        self::assertNotSame([], $literalKey->getErrors('settings'));
    }

    public function testAProviderIsNeverServedFromSettingsThatHaveBeenReplaced(): void
    {
        $index = $this->newIndex(MeilisearchProvider::class);
        $index->settings = ['url' => 'http://localhost:7700'];
        $this->persistIndex($index);

        $providers = $this->plugin()->getProviders();

        /** @var MeilisearchProvider $before */
        $before = $providers->getProviderForIndex($index);
        self::assertSame('http://localhost:7700', $before->url);

        $index->settings = ['url' => 'http://localhost:7701'];
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));

        /** @var MeilisearchProvider $after */
        $after = $providers->getProviderForIndex($index);

        // Held over, it would go on reaching the server the replaced settings named.
        self::assertSame('http://localhost:7701', $after->url);
    }

    public function testANewIndexReportsTheGenerationItIsActuallyOn(): void
    {
        $index = $this->persistIndex($this->newIndex());

        // A rebuild may only settle the generation it started against, so a model that disagreed
        // with the database could never report itself as current.
        self::assertSame(
            $this->freshIndexes()->getIndexByHandle($index->handle)?->configurationVersion,
            $index->configurationVersion,
        );
        self::assertTrue($this->plugin()->getIndexes()->markRebuildComplete($index, $index->configurationVersion));
    }

    public function testEveryIndexGetsAnIdentifierOfItsOwn(): void
    {
        $index = $this->persistIndex($this->newIndex());
        $other = $this->persistIndex($this->newIndex());

        // A GraphQL schema names an index by its uid, so two indexes sharing one would let a
        // schema granted access to either search both.
        self::assertNotEmpty($index->uid);
        self::assertNotSame('0', $index->uid);
        self::assertNotSame($index->uid, $other->uid);
        self::assertSame($index->uid, $this->freshIndexes()->getIndexByHandle($index->handle)?->uid);
    }

    public function testSearchBehaviourRoundTripsWithTheIndex(): void
    {
        $index = $this->newIndex();
        $index->getSearchSettings()->partialMatching = PartialMatchMode::Substring;
        $index->getSearchSettings()->customStopWords = ['shop'];
        $index->getSearchSettings()->typoTolerance = false;
        $this->persistIndex($index);

        $read = $this->freshIndexes()->getIndexByHandle($index->handle);

        self::assertNotNull($read);
        self::assertSame(PartialMatchMode::Substring, $read->getSearchSettings()->partialMatching);
        self::assertSame(['shop'], $read->getSearchSettings()->customStopWords);
        self::assertFalse($read->getSearchSettings()->typoTolerance);
    }

    public function testChangingSearchBehaviourDoesNotCostARebuild(): void
    {
        $index = $this->markCurrent($this->persistIndex($this->newIndex()));

        $index->getSearchSettings()->partialMatching = PartialMatchMode::Off;
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));

        // Behaviour decides how a query is read, not what the provider was given to hold.
        self::assertFalse($this->freshIndexes()->getIndexByHandle($index->handle)?->rebuildRequired);
    }

    public function testRejectsSearchBehaviourThatWouldNotWork(): void
    {
        $index = $this->newIndex();
        $index->getSearchSettings()->typoMaxDistance = 9;

        self::assertFalse($this->plugin()->getIndexes()->saveIndex($index));
        self::assertArrayHasKey('searchSettings', $index->getErrors());
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

        foreach (['slug' => 2, 'title' => 10] as $handle => $weight) {
            $this->saveField($index->id, $handle, $weight);
        }

        $weights = $this->plugin()->getSearchableFields()->getFieldsByIndexId($index->id);

        self::assertSame(['title', 'slug'], array_map(static fn($f) => $f->handle, $weights));
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
