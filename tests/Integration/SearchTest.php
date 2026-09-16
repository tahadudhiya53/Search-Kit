<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * Runs real searches through the whole pipeline to prove the site semantics an index declares.
 */
class SearchTest extends IntegrationTestCase
{
    private const TERM = 'home';
    private const MISSING_SITE_ID = 2147483600;

    public function testAnIndexScopedToOneSiteOnlySearchesThatSite(): void
    {
        foreach ($this->sitesWithMatches() as $siteId) {
            $index = $this->persistSearchableIndex($siteId);

            $result = $this->search($index);

            self::assertGreaterThan(0, $result->total, "Site $siteId should have matches.");
            self::assertSame($this->expectedTotal($siteId), $result->total);

            foreach ($result->hits as $hit) {
                self::assertSame($siteId, $hit->siteId);
            }
        }
    }

    public function testAnAllSiteIndexSearchesEverySite(): void
    {
        $index = $this->persistSearchableIndex(null);

        $result = $this->search($index);

        self::assertSame($this->expectedTotal('*'), $result->total);
        self::assertGreaterThan(1, count(array_unique(array_map(
            static fn(SearchHit $hit) => $hit->siteId,
            $result->hits,
        ))), 'An all-site search should return hits from more than one site.');
    }

    public function testAQuerySiteNarrowsAnAllSiteIndex(): void
    {
        $index = $this->persistSearchableIndex(null);
        $siteId = $this->sitesWithMatches()[0];

        $query = SearchQuery::make(self::TERM, $index->handle);
        $query->siteId = $siteId;

        $result = $this->plugin()->getSearch()->search($query);

        self::assertSame($this->expectedTotal($siteId), $result->total);

        foreach ($result->hits as $hit) {
            self::assertSame($siteId, $hit->siteId);
        }
    }

    public function testAScopedIndexAcceptsItsOwnSiteAsAQuerySite(): void
    {
        $siteId = $this->sitesWithMatches()[0];
        $index = $this->persistSearchableIndex($siteId);

        $query = SearchQuery::make(self::TERM, $index->handle);
        $query->siteId = $siteId;

        $result = $this->plugin()->getSearch()->search($query);

        self::assertSame($this->expectedTotal($siteId), $result->total);

        foreach ($result->hits as $hit) {
            self::assertSame($siteId, $hit->siteId);
        }
    }

    public function testAQuerySiteOutsideAScopedIndexIsRejected(): void
    {
        $sites = $this->sitesWithMatches();
        $index = $this->persistSearchableIndex($sites[0]);

        $query = SearchQuery::make(self::TERM, $index->handle);
        $query->siteId = $sites[1];

        $this->assertRejectedSite($query, "outside this index's scope");
    }

    public function testANonexistentSiteIsRejectedByAnAllSiteIndex(): void
    {
        $index = $this->persistSearchableIndex(null);

        $query = SearchQuery::make(self::TERM, $index->handle);
        $query->siteId = self::MISSING_SITE_ID;

        // Without this an all-site index would happily run and return nothing at all.
        $this->assertRejectedSite($query, 'No site exists');
    }

    public function testANonexistentSiteIsRejectedByAScopedIndex(): void
    {
        $index = $this->persistSearchableIndex($this->sitesWithMatches()[0]);

        $query = SearchQuery::make(self::TERM, $index->handle);
        $query->siteId = self::MISSING_SITE_ID;

        $this->assertRejectedSite($query, 'No site exists');
    }

    public function testProviderFailuresDoNotLeakInternalDetail(): void
    {
        // An element type that cannot be loaded is the simplest real failure inside the provider.
        $index = $this->newIndex();
        $index->setFields([new SearchableField([
            'elementType' => 'Tahadudhiya\SearchKit\NotAnElementType',
            'handle' => 'title',
        ])]);

        try {
            (new CraftProvider())->search(SearchQuery::make(self::TERM, $index->handle), $index);
            self::fail('A provider failure should surface as a SearchKit exception.');
        } catch (ProviderException $e) {
            self::assertStringNotContainsString('Class "', $e->getMessage());
            self::assertStringNotContainsString('not found', $e->getMessage());
            self::assertNotNull($e->getPrevious(), 'The underlying failure must stay available for logging.');
        }
    }

    private function assertRejectedSite(SearchQuery $query, string $expectedDetail): void
    {
        try {
            $this->plugin()->getSearch()->search($query);
            self::fail('An unusable site should be rejected.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('siteId', $e->getErrors());
            self::assertStringContainsString($expectedDetail, implode(' ', $e->getErrors()['siteId']));
        }
    }

    public function testHitsAndResultsAreFullyNormalized(): void
    {
        $index = $this->persistSearchableIndex(null);

        $result = $this->search($index);

        self::assertSame($index->handle, $result->indexHandle);
        self::assertSame(CraftProvider::class, $result->provider);
        self::assertSame(20, $result->limit);
        self::assertSame(0, $result->offset);
        self::assertGreaterThan(0.0, $result->executionTime);
        self::assertSame([Entry::class], $result->metadata['elementTypes']);
        self::assertNotEmpty($result->hits);

        foreach ($result->hits as $hit) {
            self::assertGreaterThan(0, $hit->elementId);
            self::assertNotNull($hit->siteId);
            self::assertSame(Entry::class, $hit->elementType);
            self::assertGreaterThan(0.0, $hit->score);
            self::assertInstanceOf(Entry::class, $hit->element);
            self::assertSame($hit->elementId, $hit->element->id);
            self::assertSame($hit->siteId, $hit->element->siteId);
            // Craft cannot report these, so SearchKit must not invent them.
            self::assertSame([], $hit->matchedFields);
            self::assertSame([], $hit->highlights);
        }
    }

    public function testScoresAreOrderedHighestFirst(): void
    {
        $index = $this->persistSearchableIndex(null);

        $scores = array_map(static fn(SearchHit $hit) => $hit->score, $this->search($index)->hits);

        $sorted = $scores;
        rsort($sorted);

        self::assertSame($sorted, $scores);
    }

    /**
     * @return int[]
     */
    private function sitesWithMatches(): array
    {
        $siteIds = array_values(array_filter(
            Craft::$app->getSites()->getAllSiteIds(),
            fn(int $siteId) => $this->expectedTotal($siteId) > 0,
        ));

        if (count($siteIds) < 2) {
            self::markTestSkipped('This project needs matching content in at least two sites.');
        }

        return $siteIds;
    }

    private function expectedTotal(int|string $siteId): int
    {
        return (int)Entry::find()->search(self::TERM)->siteId($siteId)->count();
    }

    private function persistSearchableIndex(?int $siteId): SearchIndex
    {
        $index = $this->newIndex();
        $index->siteId = $siteId;
        $this->persistIndex($index);

        $field = new SearchableField([
            'indexId' => $index->id,
            'elementType' => Entry::class,
            'handle' => 'title',
            'weight' => 10,
        ]);

        self::assertTrue($this->plugin()->getSearchableFields()->saveField($field));

        return $index;
    }

    private function search(SearchIndex $index): \Tahadudhiya\SearchKit\models\SearchResult
    {
        return $this->plugin()->getSearch()->search(SearchQuery::make(self::TERM, $index->handle));
    }
}
