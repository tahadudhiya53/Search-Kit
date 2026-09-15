<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\SearchKit;
use Tahadudhiya\SearchKit\services\Indexes;

/**
 * Runs against the Craft app booted by tests/integration-bootstrap.php, so these tests exercise
 * the real database rather than doubles.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** @var SearchIndex[] */
    private array $createdIndexes = [];

    public static function setUpBeforeClass(): void
    {
        if (Craft::$app === null) {
            self::markTestSkipped('No booted Craft application is available.');
        }
    }

    protected function tearDown(): void
    {
        // Only ever removes rows this test created, in SearchKit's own tables.
        foreach ($this->createdIndexes as $index) {
            $this->plugin()->getIndexes()->deleteIndex($index);
        }

        $this->createdIndexes = [];

        parent::tearDown();
    }

    protected function plugin(): SearchKit
    {
        $plugin = SearchKit::getInstance();
        self::assertNotNull($plugin, 'SearchKit is not installed in the Craft project.');

        return $plugin;
    }

    /**
     * An unsaved, all-sites index with a handle no other test can collide with.
     */
    protected function newIndex(): SearchIndex
    {
        return new SearchIndex([
            'name' => 'SearchKit Test Index',
            'handle' => $this->uniqueHandle(),
            'provider' => CraftProvider::class,
        ]);
    }

    protected function uniqueHandle(): string
    {
        return 'skTest' . bin2hex(random_bytes(6));
    }

    /**
     * A service that has not memoized anything, to prove a value really came back from the database.
     */
    protected function freshIndexes(): Indexes
    {
        return new Indexes();
    }

    /**
     * Saves an index and registers it for removal when the test finishes.
     */
    protected function persistIndex(SearchIndex $index): SearchIndex
    {
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index), implode(' ', $index->getErrorSummary(true)));
        $this->createdIndexes[] = $index;

        return $index;
    }
}
