<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\elements\Entry;
use Tahadudhiya\SearchKit\enums\IndexOperationStatus;
use Tahadudhiya\SearchKit\enums\IndexOperationType;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\services\Indexing;
use Tahadudhiya\SearchKit\services\IndexOperations;

/**
 * The outstanding-work table is what makes indexing recoverable, so its bookkeeping is pinned down
 * here rather than inferred from the indexing service's behaviour.
 */
class IndexOperationsTest extends IntegrationTestCase
{
    public function testRecordingWorkMakesItPending(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);
        $this->record($index, 101, IndexOperationType::Index);

        $pending = $this->operations()->getPending((int)$index->id, 10);

        self::assertCount(1, $pending);
        self::assertSame(101, $pending[0]->elementId);
        self::assertSame(IndexOperationType::Index, $pending[0]->operation);
        self::assertSame(IndexOperationStatus::Pending, $pending[0]->status);
        self::assertSame(0, $pending[0]->attempts);
    }

    public function testTheNewestIntentForAnElementReplacesTheOlderOne(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);
        $this->record($index, 101, IndexOperationType::Index);
        $this->record($index, 101, IndexOperationType::Delete);

        $pending = $this->operations()->getPending((int)$index->id, 10);

        self::assertCount(1, $pending);
        self::assertSame(IndexOperationType::Delete, $pending[0]->operation);
    }

    public function testTheSameElementInTwoSitesIsTwoPiecesOfWork(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);
        $this->record($index, 101, IndexOperationType::Index, siteId: 1);
        $this->record($index, 101, IndexOperationType::Index, siteId: 2);

        self::assertCount(2, $this->operations()->getPending((int)$index->id, 10));
    }

    public function testAFailureStaysPendingUntilItsRetriesAreUsedUp(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);
        $this->record($index, 101, IndexOperationType::Index);

        $operation = $this->operations()->getPending((int)$index->id, 10)[0];

        for ($attempt = 1; $attempt < Indexing::MAX_ATTEMPTS; $attempt++) {
            $this->operations()->recordFailure($operation, 'provider unreachable', Indexing::MAX_ATTEMPTS);
            self::assertSame(IndexOperationStatus::Pending, $operation->status);
        }

        $this->operations()->recordFailure($operation, 'provider unreachable', Indexing::MAX_ATTEMPTS);

        self::assertSame(IndexOperationStatus::Failed, $operation->status);
        self::assertSame(Indexing::MAX_ATTEMPTS, $operation->attempts);

        $failed = $this->operations()->getFailed((int)$index->id);
        self::assertCount(1, $failed);
        self::assertSame('provider unreachable', $failed[0]->error);
        self::assertCount(0, $this->operations()->getPending((int)$index->id, 10));
    }

    public function testRetryingClearsTheFailureAndItsAttempts(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);
        $this->record($index, 101, IndexOperationType::Index);

        $operation = $this->operations()->getPending((int)$index->id, 10)[0];

        for ($attempt = 0; $attempt < Indexing::MAX_ATTEMPTS; $attempt++) {
            $this->operations()->recordFailure($operation, 'provider unreachable', Indexing::MAX_ATTEMPTS);
        }

        self::assertSame(1, $this->operations()->retryFailed((int)$index->id));

        $pending = $this->operations()->getPending((int)$index->id, 10);
        self::assertCount(1, $pending);
        self::assertSame(0, $pending[0]->attempts);
        self::assertNull($pending[0]->error);
    }

    public function testCompletedWorkLeavesNoRowBehind(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);
        $this->record($index, 101, IndexOperationType::Index);

        $operation = $this->operations()->getPending((int)$index->id, 10)[0];
        $this->operations()->release($operation);

        self::assertSame(0, $this->operations()->countByStatus((int)$index->id, IndexOperationStatus::Pending));
    }

    public function testDeletingAnIndexTakesItsOutstandingWorkWithIt(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);
        $this->record($index, 101, IndexOperationType::Index);

        $indexId = (int)$index->id;
        self::assertTrue($this->plugin()->getIndexes()->deleteIndex($index));

        self::assertSame(0, $this->operations()->countByStatus($indexId, IndexOperationStatus::Pending));
    }

    public function testAWorkerCannotReleaseAnIntentThatReplacedTheOneItProcessed(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);
        $this->record($index, 101, IndexOperationType::Index);

        // The worker picks up the intent it is going to process.
        $claimed = $this->operations()->getPending((int)$index->id, 10)[0];

        // A content change lands while that worker is still busy.
        $this->record($index, 101, IndexOperationType::Delete);

        // The worker finishes the intent it started with.
        self::assertFalse($this->operations()->release($claimed));

        $pending = $this->operations()->getPending((int)$index->id, 10);
        self::assertCount(1, $pending);
        self::assertSame(IndexOperationType::Delete, $pending[0]->operation);
        self::assertNotSame($claimed->token, $pending[0]->token);

        // The newest intent is still there to be processed, and releasing it works.
        self::assertTrue($this->operations()->release($pending[0]));
        self::assertSame(0, $this->operations()->countByStatus((int)$index->id, IndexOperationStatus::Pending));
    }

    public function testAWorkerCannotFailAnIntentThatReplacedTheOneItProcessed(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);
        $this->record($index, 101, IndexOperationType::Index);

        $claimed = $this->operations()->getPending((int)$index->id, 10)[0];
        $this->record($index, 101, IndexOperationType::Index);

        self::assertFalse(
            $this->operations()->recordFailure($claimed, 'provider unreachable', Indexing::MAX_ATTEMPTS),
        );

        // The newer intent keeps its clean slate rather than inheriting the older one's failure.
        $pending = $this->operations()->getPending((int)$index->id, 10);
        self::assertCount(1, $pending);
        self::assertSame(0, $pending[0]->attempts);
        self::assertNull($pending[0]->error);
        self::assertSame(IndexOperationStatus::Pending, $pending[0]->status);
    }

    public function testEveryRecordedIntentCarriesANewToken(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);

        $this->record($index, 101, IndexOperationType::Index);
        $first = $this->operations()->getPending((int)$index->id, 10)[0];

        $this->record($index, 101, IndexOperationType::Index);
        $second = $this->operations()->getPending((int)$index->id, 10)[0];

        self::assertSame($first->id, $second->id);
        self::assertNotSame('', $first->token);
        self::assertNotSame($first->token, $second->token);
    }

    public function testAPermanentFailureSkipsTheRemainingRetries(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);
        $this->record($index, 101, IndexOperationType::Index);

        $operation = $this->operations()->getPending((int)$index->id, 10)[0];
        self::assertTrue(
            $this->operations()->recordFailure($operation, 'provider cannot delete', Indexing::MAX_ATTEMPTS, true),
        );

        self::assertSame(IndexOperationStatus::Failed, $operation->status);
        self::assertSame(1, $operation->attempts);
        self::assertCount(1, $this->operations()->getFailed((int)$index->id));
    }

    public function testRetryingNeverDisturbsANewerPendingIntent(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);
        $this->record($index, 101, IndexOperationType::Index);

        $operation = $this->operations()->getPending((int)$index->id, 10)[0];
        $this->operations()->recordFailure($operation, 'gone wrong', Indexing::MAX_ATTEMPTS, true);

        // A content change re-records the same element, which supersedes the failure.
        $this->record($index, 101, IndexOperationType::Index);

        self::assertSame(0, $this->operations()->retryFailed((int)$index->id));

        $pending = $this->operations()->getPending((int)$index->id, 10);
        self::assertCount(1, $pending);
        self::assertSame(0, $pending[0]->attempts);
    }

    public function testHasPendingReflectsOutstandingWorkOnly(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);
        self::assertFalse($this->operations()->hasPending((int)$index->id));

        $this->record($index, 101, IndexOperationType::Index);
        self::assertTrue($this->operations()->hasPending((int)$index->id));

        $operation = $this->operations()->getPending((int)$index->id, 10)[0];
        $this->operations()->recordFailure($operation, 'gone wrong', Indexing::MAX_ATTEMPTS, true);

        // A parked failure is not outstanding work; it waits for someone to retry it.
        self::assertFalse($this->operations()->hasPending((int)$index->id));
    }

    public function testARebuildOnlyDropsDeletionsWhenTheProviderDiscardsItsIndex(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);
        $this->record($index, 101, IndexOperationType::Index);
        $this->record($index, 102, IndexOperationType::Delete);

        $this->operations()->deleteSupersededByRebuild((int)$index->id, false);

        $pending = $this->operations()->getPending((int)$index->id, 10);
        self::assertCount(1, $pending);
        self::assertSame(IndexOperationType::Delete, $pending[0]->operation);

        $this->operations()->deleteSupersededByRebuild((int)$index->id, true);
        self::assertSame(0, $this->operations()->countByStatus((int)$index->id, IndexOperationStatus::Pending));
    }

    private function record(SearchIndex $index, int $elementId, IndexOperationType $operation, int $siteId = 1): void
    {
        $this->operations()->record((int)$index->id, $elementId, $siteId, Entry::class, $operation);
    }

    private function operations(): IndexOperations
    {
        return $this->plugin()->getIndexOperations();
    }
}
