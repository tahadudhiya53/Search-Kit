<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Entry;
use RuntimeException;
use Tahadudhiya\SearchKit\enums\IndexOperationType;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\Tests\Support\RecordingProvider;

/**
 * Rebuilds take the same lock as ordinary processing, and only a rebuild that left nothing behind
 * is allowed to report the index as current.
 */
class RebuildTest extends ContentTestCase
{
    private ?string $heldLock = null;

    public function testARebuildIsRefusedWhileAnotherRunOwnsTheIndex(): void
    {
        $index = $this->recordingIndex();
        $this->createEntry('SearchKit locked out of rebuild');

        $this->holdIndexLock($index);

        $result = $this->plugin()->getIndexing()->rebuild($index);

        self::assertTrue($result->locked);
        self::assertFalse($result->isComplete());
        self::assertSame(0, RecordingProvider::$rebuilds);
        self::assertSame([], RecordingProvider::$indexed);
    }

    public function testProcessingIsRefusedWhileARebuildOwnsTheIndex(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit locked out of processing');

        $this->holdIndexLock($index);

        $result = $this->plugin()->getIndexing()->processPending($index);

        self::assertTrue($result->locked);
        self::assertSame([], $this->documentsFor($entry->id));
        // Nothing was attempted, so nothing was lost.
        self::assertSame(1, $this->pendingCount($index));
    }

    public function testASuccessfulRebuildStampsTheIndexAndClearsTheRebuildFlag(): void
    {
        $index = $this->recordingIndex();
        $this->plugin()->getIndexes()->markRebuildRequired($index);
        $entry = $this->createEntry('SearchKit rebuild completely');

        $result = $this->plugin()->getIndexing()->rebuild($index);

        self::assertTrue($result->isComplete());
        self::assertSame(0, $result->failed);
        self::assertCount(1, $this->documentsFor($entry->id));

        $stored = $this->freshIndexes()->getIndexByHandle($index->handle);
        self::assertNotNull($stored?->dateLastIndexed);
        self::assertFalse($stored->rebuildRequired);
    }

    public function testAPartialRebuildNeverClaimsTheIndexIsCurrent(): void
    {
        $index = $this->recordingIndex();
        $this->plugin()->getIndexes()->markRebuildRequired($index);
        $entry = $this->createEntry('SearchKit rebuild partially');

        RecordingProvider::$indexingFailure = new RuntimeException('provider blew up');
        RecordingProvider::$failingElementIds = [(int)$entry->id];

        $result = $this->plugin()->getIndexing()->rebuild($index);

        self::assertFalse($result->isComplete());
        self::assertSame(1, $result->failed);

        $stored = $this->freshIndexes()->getIndexByHandle($index->handle);
        self::assertNull($stored?->dateLastIndexed);
        self::assertTrue($stored->rebuildRequired);

        $status = $this->plugin()->getIndexing()->getStatus($stored);
        self::assertFalse($status->isHealthy());
    }

    public function testAnElementThatFailsARebuildIsKeptQueuedAndRetriedAfterwards(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit rebuild failure retried');

        RecordingProvider::$indexingFailure = new RuntimeException('provider blew up');
        RecordingProvider::$failingElementIds = [(int)$entry->id];

        $queuedBefore = $this->queuedJobCount();
        $result = $this->plugin()->getIndexing()->rebuild($index);

        self::assertSame(1, $result->failed);

        // The element is still owed, and a job was queued once the rebuild let go of the lock.
        self::assertSame(1, $this->pendingCount($index));
        self::assertGreaterThan($queuedBefore, $this->queuedJobCount());

        // That job's work succeeds once whatever broke is fixed.
        RecordingProvider::$indexingFailure = null;
        $retried = $this->plugin()->getIndexing()->processPending($index);

        self::assertTrue($retried->isComplete());
        self::assertCount(1, $this->documentsFor($entry->id));
        self::assertSame(0, $this->pendingCount($index));
    }

    public function testARebuildDropsOutstandingIndexingItIsAboutToRedo(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit rebuild supersedes');

        self::assertSame(1, $this->pendingCount($index));

        $this->plugin()->getIndexing()->rebuild($index);

        self::assertSame(0, $this->pendingCount($index));
        self::assertCount(1, $this->documentsFor($entry->id));
    }

    public function testARebuildNeverReachesOutsideTheIndexSiteScope(): void
    {
        $siteId = $this->sectionSiteId();
        $index = $this->recordingIndex($siteId);
        $this->createEntry('SearchKit scoped rebuild');

        $result = $this->plugin()->getIndexing()->rebuild($index);

        self::assertGreaterThanOrEqual(1, $result->processed);

        foreach (RecordingProvider::$indexed as $document) {
            self::assertSame($siteId, $document->siteId, 'A rebuild indexed a site the index does not cover.');
            self::assertSame(Entry::class, $document->elementType);
        }
    }

    public function testARebuildOnlyWalksConfiguredElementTypes(): void
    {
        $index = $this->recordingIndex();
        $this->createEntry('SearchKit one element type');

        $this->plugin()->getIndexing()->rebuild($index);

        self::assertNotSame([], RecordingProvider::$indexed);

        foreach (RecordingProvider::$indexed as $document) {
            self::assertSame(Entry::class, $document->elementType);
        }
    }

    public function testADocumentCarriesTheConfiguredWeightThroughARebuild(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit weighted rebuild');

        $this->plugin()->getIndexing()->rebuild($index);

        $documents = $this->documentsFor($entry->id);
        self::assertCount(1, $documents);
        self::assertSame(['title' => 5], $documents[0]->getWeights());
    }

    public function testAChangeMadeDuringARebuildIsNotSwallowedByIt(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit changed mid rebuild');
        $this->plugin()->getIndexing()->processPending($index);

        // Stands in for a save landing while the rebuild is walking the index.
        $concurrent = (int)$entry->id;
        RecordingProvider::$onIndex = function() use ($index, $concurrent) {
            RecordingProvider::$onIndex = null;
            $this->plugin()->getIndexOperations()->record(
                (int)$index->id,
                $concurrent,
                $this->sectionSiteId(),
                Entry::class,
                IndexOperationType::Index,
            );
        };

        $result = $this->plugin()->getIndexing()->rebuild($index);

        self::assertTrue($result->isComplete());
        // The rebuild did not delete work recorded after it took its snapshot.
        self::assertSame(1, $this->pendingCount($index));
        self::assertGreaterThan(0, $this->queuedJobCount(), 'The leftover change was never queued.');

        $this->plugin()->getIndexing()->processPending($index);
        self::assertSame(0, $this->pendingCount($index));
    }

    public function testADeleteMadeDuringARebuildIsNotSwallowedByIt(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit deleted mid rebuild');
        $this->plugin()->getIndexing()->processPending($index);

        $doomed = (int)$entry->id;
        RecordingProvider::$onIndex = function() use ($index, $doomed) {
            RecordingProvider::$onIndex = null;
            $this->plugin()->getIndexOperations()->record(
                (int)$index->id,
                $doomed,
                $this->sectionSiteId(),
                Entry::class,
                IndexOperationType::Delete,
            );
        };

        $this->plugin()->getIndexing()->rebuild($index);

        $pending = $this->plugin()->getIndexOperations()->getPending((int)$index->id, 10);
        self::assertCount(1, $pending);
        self::assertSame(IndexOperationType::Delete, $pending[0]->operation);

        $this->plugin()->getIndexing()->processPending($index);

        self::assertCount(1, array_filter(
            RecordingProvider::$deleted,
            static fn(SearchDocument $document) => $document->elementId === $doomed,
        ));
    }

    public function testAProviderThatCannotDiscardItsIndexKeepsOutstandingDeletions(): void
    {
        RecordingProvider::$rebuildingSupported = false;

        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit no discard rebuild');
        $doomed = (int)$entry->id;

        $this->plugin()->getIndexOperations()->record(
            (int)$index->id,
            2147483600,
            $this->sectionSiteId(),
            Entry::class,
            IndexOperationType::Delete,
        );

        $result = $this->plugin()->getIndexing()->rebuild($index);

        self::assertSame(0, RecordingProvider::$rebuilds, 'A provider without the capability was asked to rebuild.');
        self::assertTrue($result->isComplete());
        self::assertCount(1, $this->documentsFor($doomed));

        // Nothing else would carry the deletion out, so the rebuild left it alone.
        $pending = $this->plugin()->getIndexOperations()->getPending((int)$index->id, 10);
        self::assertCount(1, $pending);
        self::assertSame(IndexOperationType::Delete, $pending[0]->operation);
    }

    public function testAPartialRebuildIsOnlySettledOnceItsLeftoversSucceed(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit settled later');

        RecordingProvider::$indexingFailure = new RuntimeException('provider blew up');
        RecordingProvider::$failingElementIds = [(int)$entry->id];

        self::assertFalse($this->plugin()->getIndexing()->rebuild($index)->isComplete());

        $stalled = $this->freshIndexes()->getIndexByHandle($index->handle);
        self::assertTrue($stalled->rebuildRequired);
        self::assertTrue($stalled->rebuildPending);
        self::assertNull($stalled->dateLastIndexed);

        // The leftover still fails, so nothing is settled yet.
        $this->plugin()->getIndexing()->processPending($stalled);
        $stalled = $this->freshIndexes()->getIndexByHandle($index->handle);
        self::assertTrue($stalled->rebuildRequired);
        self::assertNull($stalled->dateLastIndexed);

        RecordingProvider::$indexingFailure = null;
        $settled = $this->plugin()->getIndexing()->processPending($stalled);

        self::assertTrue($settled->isComplete());

        $stored = $this->freshIndexes()->getIndexByHandle($index->handle);
        self::assertFalse($stored->rebuildRequired);
        self::assertFalse($stored->rebuildPending);
        self::assertNotNull($stored->dateLastIndexed);
        self::assertTrue($this->plugin()->getIndexing()->getStatus($stored)->isHealthy());
    }

    public function testDrainingOrdinaryWorkNeverClearsARebuildOwedByAConfigurationChange(): void
    {
        $index = $this->recordingIndex();
        $this->markCurrent($index);
        $this->plugin()->getIndexes()->markRebuildRequired($index);

        $this->createEntry('SearchKit ordinary work');
        $stale = $this->freshIndexes()->getIndexByHandle($index->handle);

        self::assertTrue($this->plugin()->getIndexing()->processPending($stale)->isComplete());

        // Incremental work indexes what changed; it does not cover the whole configuration.
        self::assertTrue($this->freshIndexes()->getIndexByHandle($index->handle)->rebuildRequired);
    }

    public function testADisabledIndexIsNeverRebuilt(): void
    {
        $index = $this->recordingIndex();
        $this->createEntry('SearchKit disabled rebuild');

        $index->enabled = false;
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));

        $result = $this->plugin()->getIndexing()->rebuild($index);

        self::assertSame(0, $result->processed);
        self::assertSame(0, RecordingProvider::$rebuilds);
        self::assertSame([], RecordingProvider::$indexed);
    }

    /**
     * Takes the index's own lock, standing in for another worker already writing to it.
     */
    private function holdIndexLock(SearchIndex $index): void
    {
        $this->heldLock = $this->plugin()->getIndexing()->getLockKey($index);

        self::assertTrue(Craft::$app->getMutex()->acquire($this->heldLock), 'The index lock was already held.');
    }

    protected function tearDown(): void
    {
        if ($this->heldLock !== null) {
            Craft::$app->getMutex()->release($this->heldLock);
            $this->heldLock = null;
        }

        parent::tearDown();
    }
}
