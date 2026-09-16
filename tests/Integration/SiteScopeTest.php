<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\elements\Entry;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\Tests\Support\BareHitProvider;

/**
 * Covers what a result says about the site it came from, and proves an element is only ever loaded
 * from the site its hit names.
 */
class SiteScopeTest extends SearchContentTestCase
{
    private const TERM = 'zqxwlocale';

    private Entry $entry;

    /** @var int[] */
    private array $siteIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        BareHitProvider::reset();

        $this->siteIds = $this->fieldSectionSiteIds();

        if (count($this->siteIds) < 2) {
            self::markTestSkipped('This project needs a section enabled for at least two sites.');
        }

        $this->entry = $this->createPage('Zqxwlocale Shared Page');

        // The entry propagates to every site the section covers; each variant is indexed there.
        foreach ($this->siteIds as $siteId) {
            $variant = Entry::find()->id($this->entry->id)->siteId($siteId)->status(null)->one();

            if ($variant !== null) {
                \Craft::$app->getSearch()->indexElementAttributes($variant);
            }
        }
    }

    protected function tearDown(): void
    {
        BareHitProvider::reset();

        parent::tearDown();
    }

    public function testAnAllSiteIndexReportsTheSiteOfEveryHit(): void
    {
        $result = $this->search(null);

        self::assertSame(count($this->siteIds), $result->total);

        foreach ($result->hits as $hit) {
            self::assertContains($hit->siteId, $this->siteIds);
            self::assertSame($hit->siteId, $hit->element?->siteId, 'A hit must carry its own site’s element.');
        }

        self::assertEqualsCanonicalizing(
            $this->siteIds,
            array_map(static fn(SearchHit $hit) => $hit->siteId, $result->hits),
        );
    }

    public function testAQuerySiteNarrowsAnAllSiteIndexToOneVariant(): void
    {
        foreach ($this->siteIds as $siteId) {
            $result = $this->search(null, ['site' => $siteId]);

            self::assertSame(1, $result->total);
            self::assertSame($siteId, $result->hits[0]->siteId);
            self::assertSame($siteId, $result->hits[0]->element?->siteId);
        }
    }

    public function testAnIndexScopedToOneSiteNeverReachesAnother(): void
    {
        foreach ($this->siteIds as $siteId) {
            $result = $this->search($siteId);

            self::assertSame(1, $result->total);
            self::assertSame($siteId, $result->hits[0]->siteId);
        }
    }

    public function testAHitWithoutASiteInheritsTheScopeItWasSearchedIn(): void
    {
        $siteId = $this->siteIds[1];
        $index = $this->persistIndexWithFields([Entry::class => 'title'], BareHitProvider::class, $siteId);

        // An external provider need not report a site when the index only covers one.
        BareHitProvider::$hits = [new SearchHit([
            'elementId' => (int)$this->entry->id,
            'elementType' => Entry::class,
        ])];

        $hit = $this->plugin()->getSearch()->search(SearchQuery::create($index->handle, self::TERM))->hits[0];

        self::assertSame($siteId, $hit->siteId);
        self::assertSame($siteId, $hit->element?->siteId, 'Hydration must load the site the hit names.');
    }

    public function testAHitWithoutASiteIsRejectedWhenTheScopeCannotDecide(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title'], BareHitProvider::class, null);

        BareHitProvider::$hits = [new SearchHit([
            'elementId' => (int)$this->entry->id,
            'elementType' => Entry::class,
        ])];

        // Picking a site here would be a guess, and the wrong one would be a different element.
        $this->expectException(ProviderException::class);
        $this->plugin()->getSearch()->search(SearchQuery::create($index->handle, self::TERM));
    }

    public function testAHitFromOutsideTheSearchedScopeIsRejected(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title'], BareHitProvider::class, $this->siteIds[0]);

        BareHitProvider::$hits = [new SearchHit([
            'elementId' => (int)$this->entry->id,
            'siteId' => $this->siteIds[1],
            'elementType' => Entry::class,
        ])];

        $this->expectException(ProviderException::class);
        $this->plugin()->getSearch()->search(SearchQuery::create($index->handle, self::TERM));
    }

    public function testANarrowedQueryIsNotWidenedByAProvider(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title'], BareHitProvider::class, null);

        BareHitProvider::$hits = [new SearchHit([
            'elementId' => (int)$this->entry->id,
            'siteId' => $this->siteIds[1],
            'elementType' => Entry::class,
        ])];

        $query = SearchQuery::create($index->handle, self::TERM, ['site' => $this->siteIds[0]]);

        $this->expectException(ProviderException::class);
        $this->plugin()->getSearch()->search($query);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function search(?int $indexSiteId, array $params = []): SearchResult
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class, $indexSiteId);

        return $this->searchIndex($index->handle, self::TERM, $params);
    }
}
