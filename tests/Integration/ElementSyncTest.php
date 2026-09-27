<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use RuntimeException;
use Tahadudhiya\SearchKit\enums\IndexOperationType;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\jobs\ProcessIndexOperations;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\services\Indexing;
use Tahadudhiya\SearchKit\Tests\Support\FailingDocuments;
use Tahadudhiya\SearchKit\Tests\Support\RecordingProvider;

/**
 * The whole synchronisation path, from a real content change through to what a provider is handed.
 */
class ElementSyncTest extends ContentTestCase
{
    public function testASavedEntryBecomesOutstandingWorkAndReachesTheProvider(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit winter boots');

        $pending = $this->plugin()->getIndexOperations()->getPending((int)$index->id, 10);
        self::assertCount(1, $pending);
        self::assertSame((int)$entry->id, $pending[0]->elementId);
        self::assertSame(IndexOperationType::Index, $pending[0]->operation);

        $result = $this->plugin()->getIndexing()->processPending($index);
        self::assertTrue($result->isComplete());
        self::assertSame(1, $result->processed);

        $documents = $this->documentsFor($entry->id);
        self::assertCount(1, $documents);
        self::assertSame('SearchKit winter boots', $documents[0]->getValue('title'));
        self::assertSame(5, $documents[0]->getWeight('title'));
        self::assertSame(0, $this->pendingCount($index));
        self::assertNotNull($this->freshIndexes()->getIndexByHandle($index->handle)?->dateLastIndexed);
    }

    public function testAnUpdatedEntryIsIndexedAgainWithItsNewContent(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit first title');

        $this->plugin()->getIndexing()->processPending($index);

        $entry->title = 'SearchKit second title';
        self::assertTrue(Craft::$app->getElements()->saveElement($entry));

        self::assertSame(1, $this->plugin()->getIndexing()->processPending($index)->processed);

        $documents = $this->documentsFor($entry->id);
        self::assertCount(2, $documents);
        self::assertSame('SearchKit second title', $documents[1]->getValue('title'));
    }

    public function testADeletedEntryIsRemovedFromTheProvider(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit doomed entry');
        $elementId = (int)$entry->id;

        $this->plugin()->getIndexing()->processPending($index);

        self::assertTrue(Craft::$app->getElements()->deleteElement($entry, true));
        $this->createdEntries = [];

        $pending = $this->plugin()->getIndexOperations()->getPending((int)$index->id, 10);
        self::assertCount(1, $pending);
        self::assertSame(IndexOperationType::Delete, $pending[0]->operation);

        self::assertSame(1, $this->plugin()->getIndexing()->processPending($index)->processed);

        $deleted = array_values(array_filter(
            RecordingProvider::$deleted,
            static fn(SearchDocument $document) => $document->elementId === $elementId,
        ));

        self::assertCount(1, $deleted);
        self::assertTrue($deleted[0]->isEmpty());
        self::assertSame(0, $this->pendingCount($index));
    }

    public function testAFailingProviderIsRetriedAndThenParkedForAnAdministrator(): void
    {
        $index = $this->recordingIndex();
        RecordingProvider::$indexingFailure = new RuntimeException('connection string: secret');

        $entry = $this->createEntry('SearchKit unreachable provider');

        for ($attempt = 1; $attempt <= Indexing::MAX_ATTEMPTS; $attempt++) {
            $result = $this->plugin()->getIndexing()->processPending($index);
            self::assertFalse($result->isComplete());
            self::assertSame(1, $result->failed);
        }

        $failed = $this->plugin()->getIndexOperations()->getFailed((int)$index->id);
        self::assertCount(1, $failed);
        self::assertSame((int)$entry->id, $failed[0]->elementId);
        self::assertSame(Indexing::MAX_ATTEMPTS, $failed[0]->attempts);
        self::assertSame(0, $this->pendingCount($index));

        // An unexpected provider exception is never shown verbatim; its detail belongs in the log.
        self::assertStringNotContainsString('secret', (string)$failed[0]->error);
        self::assertStringContainsString('Search Kit log', (string)$failed[0]->error);

        RecordingProvider::$indexingFailure = null;
        self::assertSame(1, $this->plugin()->getIndexing()->retryFailed($index));
        self::assertSame(1, $this->plugin()->getIndexing()->processPending($index)->processed);

        self::assertCount(1, $this->documentsFor($entry->id));
        self::assertSame(0, $this->pendingCount($index));
    }

    public function testSearchKitsOwnFailuresAreShownAsWritten(): void
    {
        $index = $this->recordingIndex();
        RecordingProvider::$indexingFailure = new ProviderException('The search provider is unreachable.');

        $this->createEntry('SearchKit readable failure');
        $this->plugin()->getIndexing()->processPending($index);

        $pending = $this->plugin()->getIndexOperations()->getPending((int)$index->id, 10);
        self::assertSame('The search provider is unreachable.', $pending[0]->error);
    }

    public function testAnExtractionFailureIsNeverMistakenForASuccessfulEmptyDocument(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit unreadable entry');

        $indexing = new Indexing();
        $indexing->setDocuments(new FailingDocuments());

        for ($attempt = 1; $attempt <= Indexing::MAX_ATTEMPTS; $attempt++) {
            $result = $indexing->processPending($index);

            self::assertSame(1, $result->failed);
            self::assertFalse($result->isComplete());
            // Nothing reached the provider, and the work was not released as done.
            self::assertSame([], $this->documentsFor($entry->id));
        }

        $failed = $this->plugin()->getIndexOperations()->getFailed((int)$index->id);
        self::assertCount(1, $failed);
        self::assertStringContainsString('Could not read', (string)$failed[0]->error);

        // The real builder can still index it once whatever broke is fixed.
        self::assertSame(1, $this->plugin()->getIndexing()->retryFailed($index));
        self::assertSame(1, $this->plugin()->getIndexing()->processPending($index)->processed);
        self::assertCount(1, $this->documentsFor($entry->id));
    }

    public function testRepeatedSavesCollapseIntoOneIndexOperation(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit coalescing one');

        $entry->title = 'SearchKit coalescing two';
        Craft::$app->getElements()->saveElement($entry);
        $entry->title = 'SearchKit coalescing three';
        Craft::$app->getElements()->saveElement($entry);

        self::assertSame(1, $this->pendingCount($index));
        self::assertSame(1, $this->plugin()->getIndexing()->processPending($index)->processed);

        $documents = $this->documentsFor($entry->id);
        self::assertCount(1, $documents);
        self::assertSame('SearchKit coalescing three', $documents[0]->getValue('title'));
    }

    public function testASaveFollowedByADeleteEndsAsADelete(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit save then delete');
        $elementId = (int)$entry->id;

        Craft::$app->getElements()->deleteElement($entry, true);
        $this->createdEntries = [];

        $pending = $this->plugin()->getIndexOperations()->getPending((int)$index->id, 10);
        self::assertCount(1, $pending);
        self::assertSame(IndexOperationType::Delete, $pending[0]->operation);

        $this->plugin()->getIndexing()->processPending($index);

        self::assertSame([], $this->documentsFor($elementId));
        self::assertCount(1, array_filter(
            RecordingProvider::$deleted,
            static fn(SearchDocument $document) => $document->elementId === $elementId,
        ));
    }

    public function testADeleteFollowedByARestoreEndsAsAnIndex(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit delete then restore');

        Craft::$app->getElements()->deleteElement($entry);
        self::assertTrue(Craft::$app->getElements()->restoreElement($entry));

        $pending = $this->plugin()->getIndexOperations()->getPending((int)$index->id, 10);
        self::assertCount(1, $pending);
        self::assertSame(IndexOperationType::Index, $pending[0]->operation);

        self::assertSame(1, $this->plugin()->getIndexing()->processPending($index)->processed);
        self::assertCount(1, $this->documentsFor($entry->id));
    }

    public function testASaveDeleteSaveSequenceEndsAsAnIndex(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit save delete save');

        Craft::$app->getElements()->deleteElement($entry);
        Craft::$app->getElements()->restoreElement($entry);
        $entry->title = 'SearchKit final title';
        Craft::$app->getElements()->saveElement($entry);

        $pending = $this->plugin()->getIndexOperations()->getPending((int)$index->id, 10);
        self::assertCount(1, $pending);
        self::assertSame(IndexOperationType::Index, $pending[0]->operation);

        $this->plugin()->getIndexing()->processPending($index);

        $documents = $this->documentsFor($entry->id);
        self::assertCount(1, $documents);
        self::assertSame('SearchKit final title', $documents[0]->getValue('title'));
    }

    public function testADraftIsNeverTrackedAsOrdinaryContent(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit drafted entry');

        $this->plugin()->getIndexing()->processPending($index);

        $draft = Craft::$app->getDrafts()->createDraft($entry, (int)$entry->authorId ?: null);

        self::assertSame(0, $this->pendingCount($index));
        self::assertSame([], $this->documentsFor($draft->id));
    }

    public function testADisabledIndexIsNeverWrittenTo(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit disabled before processing');

        $index->enabled = false;
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));

        $result = $this->plugin()->getIndexing()->processPending($index);

        self::assertSame(0, $result->processed);
        self::assertSame([], $this->documentsFor($entry->id));
        // The work is kept, not thrown away, so re-enabling does not silently lose it.
        self::assertSame(1, $this->pendingCount($index));
    }

    public function testAJobForAnIndexItCannotDrainDoesNotQueueItselfForever(): void
    {
        $index = $this->recordingIndex();
        $this->createEntry('SearchKit never drains');

        $index->enabled = false;
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));

        $queued = $this->queuedJobCount();
        (new ProcessIndexOperations(['indexId' => (int)$index->id]))->execute(Craft::$app->getQueue());

        self::assertSame(1, $this->pendingCount($index));
        self::assertSame($queued, $this->queuedJobCount());
    }

    public function testRebuildingIndexesTheIndexesContentFromScratch(): void
    {
        $index = $this->recordingIndex();
        $entry = $this->createEntry('SearchKit rebuild me');

        $result = $this->plugin()->getIndexing()->rebuild($index);

        self::assertSame(1, RecordingProvider::$rebuilds);
        self::assertTrue($result->isComplete());
        self::assertGreaterThanOrEqual(1, $result->processed);
        self::assertCount(1, $this->documentsFor($entry->id));
        // A rebuild supersedes whatever the save queued.
        self::assertSame(0, $this->pendingCount($index));
    }

    public function testAnIndexIsNotGivenElementTypesItIsNotConfiguredFor(): void
    {
        $index = $this->persistIndexWithFields(
            [Asset::class => 'title'],
            RecordingProvider::class,
            $this->sectionSiteId(),
        );

        $this->createEntry('SearchKit irrelevant entry');

        self::assertSame(0, $this->pendingCount($index));
    }

    public function testAProviderThatCannotBeLoadedIsReportedRatherThanThrowing(): void
    {
        $index = $this->recordingIndex();
        $index->provider = 'Tahadudhiya\\SearchKit\\NoSuchProvider';

        // Saved without validation, standing in for a provider a plugin stopped supplying.
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index, false));

        $status = $this->plugin()->getIndexing()->getStatus($this->freshIndexes()->getIndexByHandle($index->handle));

        self::assertNotNull($status->provider);
        self::assertFalse($status->provider->available);
        self::assertFalse($status->isHealthy());
        self::assertFalse($status->canIndex);
        // The class name and the underlying error belong in the log, not in the control panel.
        self::assertStringNotContainsString('NoSuchProvider', (string)$status->provider->message);
    }

    public function testAnIndexScopedToAnotherSiteIsNeverGivenTheElement(): void
    {
        $index = $this->recordingIndex($this->aSiteOtherThan($this->sectionSiteId()));

        $entry = $this->createEntry('SearchKit wrong site');

        self::assertSame(0, $this->pendingCount($index));
        self::assertSame([], $this->documentsFor($entry->id));
    }

    public function testAnAllSiteIndexIsGivenTheElementWhicheverSiteItIsIn(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title'], RecordingProvider::class);

        $entry = $this->createEntry('SearchKit any site');
        $this->plugin()->getIndexing()->processPending($index);

        $documents = $this->documentsFor($entry->id);
        self::assertCount(1, $documents);
        self::assertSame((int)$entry->siteId, $documents[0]->siteId);
    }

    public function testADisabledIndexIsLeftAlone(): void
    {
        $index = $this->recordingIndex();
        $index->enabled = false;
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));

        $this->createEntry('SearchKit ignored entry');

        self::assertSame(0, $this->pendingCount($index));
    }

    public function testAProviderThatCannotDeleteIsNotOwedDeletions(): void
    {
        $index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->sectionSiteId(),
        );

        $entry = $this->createEntry('SearchKit craft provider entry');
        self::assertSame(1, $this->pendingCount($index));

        self::assertTrue(Craft::$app->getElements()->deleteElement($entry, true));
        $this->createdEntries = [];

        // The save is still outstanding, but nothing asked Craft to delete what it clears itself.
        foreach ($this->plugin()->getIndexOperations()->getPending((int)$index->id, 10) as $operation) {
            self::assertSame(IndexOperationType::Index, $operation->operation);
        }
    }

    public function testAnIdleIndexIsQueuedOnceRatherThanOncePerChange(): void
    {
        $index = $this->recordingIndex();
        $before = $this->queuedJobCount();

        $entry = $this->createEntry('SearchKit queue once');
        $afterFirst = $this->queuedJobCount();

        $entry->title = 'SearchKit queue once again';
        Craft::$app->getElements()->saveElement($entry);

        // The index already owed work, so the job already on its way will pick this up too.
        self::assertSame(1, $afterFirst - $before);
        self::assertSame($afterFirst, $this->queuedJobCount());

        // Once the work is drained the index is idle again, so the next change queues a new job.
        $this->plugin()->getIndexing()->processPending($index);
        $entry->title = 'SearchKit queue a third time';
        Craft::$app->getElements()->saveElement($entry);

        self::assertSame($afterFirst + 1, $this->queuedJobCount());
    }
}
