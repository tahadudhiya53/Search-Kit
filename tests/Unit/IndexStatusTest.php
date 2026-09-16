<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\models\IndexingResult;
use Tahadudhiya\SearchKit\models\IndexStatus;
use Tahadudhiya\SearchKit\models\ProviderStatus;

/**
 * What an index reports about itself has to be true, so these pin down every way it can fall short
 * of “current”.
 */
class IndexStatusTest extends TestCase
{
    public function testAnIndexWithNothingOutstandingIsHealthy(): void
    {
        self::assertTrue($this->currentStatus()->isHealthy());
    }

    public function testADisabledIndexIsNeverReportedAsCurrent(): void
    {
        $status = $this->currentStatus();
        $status->enabled = false;

        self::assertFalse($status->isHealthy());
    }

    public function testOutstandingWorkMeansTheIndexIsNotCurrentYet(): void
    {
        $status = $this->currentStatus();
        $status->pending = 1;

        self::assertFalse($status->isHealthy());
    }

    public function testFailuresMeanTheIndexIsNotHealthy(): void
    {
        $status = $this->currentStatus();
        $status->failed = 1;

        self::assertFalse($status->isHealthy());
    }

    public function testAnOwedRebuildMeansTheIndexIsNotHealthy(): void
    {
        $status = $this->currentStatus();
        $status->rebuildRequired = true;

        self::assertFalse($status->isHealthy());
    }

    public function testAnUnavailableProviderMeansTheIndexIsNotHealthy(): void
    {
        $status = $this->currentStatus();
        $status->provider = ProviderStatus::unavailable('Cannot reach the provider.');

        self::assertFalse($status->isHealthy());
    }

    public function testALockedRunReportsNeitherSuccessNorFailure(): void
    {
        $result = IndexingResult::locked();

        self::assertTrue($result->locked);
        self::assertFalse($result->isComplete());
        self::assertSame(0, $result->processed);
    }

    public function testARunWithFailuresIsNeverComplete(): void
    {
        $result = new IndexingResult(['processed' => 9, 'failed' => 1, 'total' => 10]);

        self::assertFalse($result->isComplete());
    }

    public function testARunThatFinishedEverythingIsComplete(): void
    {
        $result = new IndexingResult(['processed' => 10, 'total' => 10]);

        self::assertTrue($result->isComplete());
    }

    private function currentStatus(): IndexStatus
    {
        return new IndexStatus([
            'indexHandle' => 'siteSearch',
            'provider' => ProviderStatus::available(),
        ]);
    }
}
