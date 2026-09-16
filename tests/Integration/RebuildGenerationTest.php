<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\elements\Entry;
use DateTime;
use RuntimeException;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\Tests\Support\RecordingProvider;

/**
 * A rebuild walks one generation of an index's configuration. If the configuration moves on while
 * it is working, the rebuild it finishes belongs to the old generation and may not report the new
 * one as indexed.
 */
class RebuildGenerationTest extends ContentTestCase
{
    private const LAST_INDEXED = '2020-01-02 03:04:05';

    public function testAConfigurationChangeDuringARebuildIsNotReportedAsRebuilt(): void
    {
        $index = $this->rebuiltIndex();
        $startingVersion = $this->stored($index)->configurationVersion;

        $this->changeConfigurationDuringRebuild(fn(SearchIndex $live) => $this->saveFields($live, ['slug']));

        $result = $this->plugin()->getIndexing()->rebuild($index);

        self::assertTrue($result->superseded);
        self::assertFalse($result->isComplete());
        // Nothing failed: the provider was fine, the configuration simply moved on.
        self::assertSame(0, $result->failed);

        $stored = $this->stored($index);
        self::assertGreaterThan($startingVersion, $stored->configurationVersion);
        self::assertSame('slug', $stored->getFields()[0]->handle);
        self::assertTrue($stored->rebuildRequired, 'An old rebuild cleared the new configuration.');
        self::assertFalse($stored->rebuildPending);
        self::assertSame(
            self::LAST_INDEXED,
            $stored->dateLastIndexed?->format('Y-m-d H:i:s'),
            'An old rebuild advanced the new configuration’s last-indexed time.',
        );
        self::assertFalse($this->plugin()->getIndexing()->getStatus($stored)->isHealthy());
    }

    public function testTheNewConfigurationCanStillBeRebuiltNormally(): void
    {
        $index = $this->rebuiltIndex();
        $this->changeConfigurationDuringRebuild(fn(SearchIndex $live) => $this->saveFields($live, ['slug']));

        self::assertTrue($this->plugin()->getIndexing()->rebuild($index)->superseded);

        $result = $this->plugin()->getIndexing()->rebuild($this->stored($index));

        self::assertTrue($result->isComplete());
        self::assertFalse($result->superseded);

        $stored = $this->stored($index);
        self::assertFalse($stored->rebuildRequired);
        self::assertFalse($stored->rebuildPending);
        self::assertNotNull($stored->dateLastIndexed);
        self::assertTrue($this->plugin()->getIndexing()->getStatus($stored)->isHealthy());
    }

    public function testTheLastOfSeveralConfigurationChangesIsTheOneLeftOwingARebuild(): void
    {
        $index = $this->rebuiltIndex();

        $this->changeConfigurationDuringRebuild(function(SearchIndex $live) {
            $this->saveFields($live, ['slug']);
            $this->saveFields($this->stored($live), ['title', 'slug']);
        });

        self::assertTrue($this->plugin()->getIndexing()->rebuild($index)->superseded);

        $stored = $this->stored($index);
        self::assertSame(
            ['slug', 'title'],
            array_values(array_map(static fn(SearchableField $f) => $f->handle, $stored->getFields())),
        );
        self::assertTrue($stored->rebuildRequired);
        self::assertSame(self::LAST_INDEXED, $stored->dateLastIndexed?->format('Y-m-d H:i:s'));
    }

    public function testAProviderChangedDuringARebuildIsNotReportedAsRebuilt(): void
    {
        $index = $this->rebuiltIndex();

        $this->changeConfigurationDuringRebuild(function(SearchIndex $live) {
            $live->provider = CraftProvider::class;
            self::assertTrue($this->plugin()->getIndexes()->saveIndex($live));
        });

        self::assertTrue($this->plugin()->getIndexing()->rebuild($index)->superseded);

        $stored = $this->stored($index);
        self::assertSame(CraftProvider::class, $stored->provider);
        self::assertTrue($stored->rebuildRequired);
        self::assertSame(self::LAST_INDEXED, $stored->dateLastIndexed?->format('Y-m-d H:i:s'));
    }

    public function testAWeightChangedDuringARebuildIsNotReportedAsRebuilt(): void
    {
        $index = $this->rebuiltIndex();

        $this->changeConfigurationDuringRebuild(function(SearchIndex $live) {
            self::assertTrue($this->plugin()->getSearchableFields()->saveFieldsForIndex($live, [
                new SearchableField(['elementType' => Entry::class, 'handle' => 'title', 'weight' => 10]),
            ]));
        });

        self::assertTrue($this->plugin()->getIndexing()->rebuild($index)->superseded);

        $stored = $this->stored($index);
        self::assertSame(10, $stored->getFields()[0]->weight);
        self::assertTrue($stored->rebuildRequired);
    }

    public function testASiteScopeChangedDuringARebuildIsNotReportedAsRebuilt(): void
    {
        $index = $this->rebuiltIndex();
        $scopedTo = $index->siteId;

        $this->changeConfigurationDuringRebuild(function(SearchIndex $live) {
            $live->siteId = null;
            self::assertTrue($this->plugin()->getIndexes()->saveIndex($live));
        });

        self::assertTrue($this->plugin()->getIndexing()->rebuild($index)->superseded);

        $stored = $this->stored($index);
        self::assertNotSame($scopedTo, $stored->siteId);
        self::assertNull($stored->siteId);
        self::assertTrue($stored->rebuildRequired);
    }

    public function testAFailedRebuildCannotTouchTheNewConfigurationsStateEither(): void
    {
        $index = $this->rebuiltIndex();
        $entry = $this->createEntry('SearchKit superseded failure');

        // The walk fails on this element, and the configuration moves on while it runs.
        RecordingProvider::$failingElementIds = [(int)$entry->id];
        RecordingProvider::$indexingFailure = new RuntimeException('provider blew up');
        $this->changeConfigurationDuringRebuild(fn(SearchIndex $live) => $this->saveFields($live, ['slug']));

        $result = $this->plugin()->getIndexing()->rebuild($index);

        self::assertGreaterThan(0, $result->failed);
        self::assertTrue($result->superseded);

        $stored = $this->stored($index);
        self::assertTrue($stored->rebuildRequired);
        // The new configuration was never walked, so it is owed a rebuild, not half way through one.
        self::assertFalse($stored->rebuildPending, 'An old rebuild marked the new configuration half-built.');
        self::assertSame(self::LAST_INDEXED, $stored->dateLastIndexed?->format('Y-m-d H:i:s'));
    }

    public function testASaveThatChangesNothingStartsNoNewGeneration(): void
    {
        $index = $this->rebuiltIndex();
        $version = $this->stored($index)->configurationVersion;

        $live = $this->stored($index);
        $live->name = 'A purely administrative rename';
        self::assertTrue($this->plugin()->getIndexes()->saveIndexConfiguration($live, [
            new SearchableField(['elementType' => Entry::class, 'handle' => 'title', 'weight' => 5]),
        ]));

        $stored = $this->stored($index);
        self::assertSame($version, $stored->configurationVersion);
        self::assertFalse($stored->rebuildRequired);
    }

    public function testARolledBackSaveStartsNoNewGeneration(): void
    {
        $index = $this->rebuiltIndex();
        $version = $this->stored($index)->configurationVersion;

        $live = $this->stored($index);
        $live->provider = CraftProvider::class;

        self::assertFalse($this->plugin()->getIndexes()->saveIndexConfiguration($live, [
            new SearchableField(['elementType' => Entry::class, 'handle' => 'notAFieldAnywhere']),
        ]));

        $stored = $this->stored($index);
        self::assertSame($version, $stored->configurationVersion);
        self::assertSame(RecordingProvider::class, $stored->provider);
        self::assertFalse($stored->rebuildRequired);
        self::assertSame('title', $stored->getFields()[0]->handle);
    }

    public function testEveryEffectiveChangeStartsANewGenerationEvenWhenOneIsAlreadyOwed(): void
    {
        $index = $this->rebuiltIndex();

        $this->saveFields($this->stored($index), ['slug']);
        $first = $this->stored($index);
        $firstVersion = $first->configurationVersion;
        self::assertTrue($first->rebuildRequired);

        $this->saveFields($first, ['title']);

        // Without a new generation here, a rebuild of the first change would settle the second.
        self::assertGreaterThan($firstVersion, $this->stored($index)->configurationVersion);
    }

    /**
     * Runs the given change once, part way through the next rebuild's walk.
     */
    private function changeConfigurationDuringRebuild(callable $change): void
    {
        RecordingProvider::$onIndex = function() use ($change) {
            RecordingProvider::$onIndex = null;
            $change($this->stored($this->index));
        };
    }

    private ?SearchIndex $index = null;

    private function rebuiltIndex(): SearchIndex
    {
        $this->index = $this->recordingIndex();
        $this->createEntry('SearchKit generation walk');

        // A distinct completion time, so an old rebuild advancing it is unmistakable.
        return $this->markCurrent($this->stored($this->index), new DateTime(self::LAST_INDEXED));
    }

    /**
     * @param string[] $handles
     */
    private function saveFields(SearchIndex $index, array $handles): void
    {
        $fields = array_map(
            static fn(string $handle) => new SearchableField([
                'elementType' => Entry::class,
                'handle' => $handle,
                'weight' => 5,
            ]),
            $handles,
        );

        self::assertTrue($this->plugin()->getSearchableFields()->saveFieldsForIndex($index, $fields));
    }

    private function stored(SearchIndex $index): SearchIndex
    {
        $stored = $this->freshIndexes()->getIndexByHandle($index->handle);
        self::assertNotNull($stored);
        $this->plugin()->getSearchableFields()->attachFields($stored);

        return $stored;
    }
}
