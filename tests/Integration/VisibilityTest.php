<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use DateTime;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\UnauthorizedQueryException;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\Tests\Support\BareHitProvider;

/**
 * Proves what a search may return: content Craft publishes to anyone, and anything else only to an
 * administrator. Entry statuses are the sharp edge here — `enabled` is not the same as `live`.
 */
class VisibilityTest extends SearchContentTestCase
{
    private const TERM = 'zqxwvisible';

    private SearchIndex $index;
    private Entry $live;
    private Entry $hidden;
    private Entry $scheduled;
    private Entry $expired;

    protected function setUp(): void
    {
        parent::setUp();

        BareHitProvider::reset();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->fieldSectionSiteId(),
        );

        $this->live = $this->createPage('Zqxwvisible Published Page');
        $this->hidden = $this->createPage('Zqxwvisible Unpublished Page', enabled: false);
        $this->scheduled = $this->createPage('Zqxwvisible Scheduled Page', postDate: new DateTime('+10 days'));
        $this->expired = $this->expiredPage('Zqxwvisible Expired Page');
    }

    protected function tearDown(): void
    {
        BareHitProvider::reset();

        parent::tearDown();
    }

    public function testAnAnonymousSearchOnlyFindsPublishedContent(): void
    {
        $ids = $this->idsOf($this->search());

        self::assertContains($this->live->id, $ids);
        self::assertNotContains($this->hidden->id, $ids);
        self::assertNotContains($this->scheduled->id, $ids, 'Scheduled content is not published yet.');
        self::assertNotContains($this->expired->id, $ids, 'Expired content is no longer published.');
    }

    public function testLiveIsAPublicStatusForEntries(): void
    {
        self::assertSame([$this->live->id], $this->idsOf($this->search(['status' => 'live'])));
    }

    public function testEnabledIsNotAPublicStatusForEntries(): void
    {
        // Craft's `enabled` covers scheduled and expired entries too, so it is not public content.
        $this->expectException(UnauthorizedQueryException::class);
        $this->search(['status' => 'enabled']);
    }

    public function testEnabledWouldOtherwiseHaveExposedScheduledAndExpiredContent(): void
    {
        Craft::$app->getUser()->setIdentity($this->adminUser());

        $ids = $this->idsOf($this->search(['status' => 'enabled']));

        self::assertContains($this->scheduled->id, $ids, 'This is exactly what anonymous visitors must not receive.');
        self::assertContains($this->expired->id, $ids);
    }

    public function testEnabledIsPublicWhereCraftPublishesIt(): void
    {
        $category = $this->createCategory('Zqxwvisible Category');
        $index = $this->persistIndexWithFields(
            [Category::class => 'title'],
            CraftProvider::class,
            $this->fieldSectionSiteId(),
        );

        // A category has no scheduling, so `enabled` is what Craft publishes for it.
        $result = $this->searchIndex($index->handle, self::TERM, ['status' => 'enabled']);

        self::assertSame([(int)$category->id], $this->idsOf($result));
    }

    public function testAStatusOneElementTypeDoesNotHaveIsJudgedOnTheOnesThatDo(): void
    {
        $this->createCategory('Zqxwvisible Category');
        $index = $this->persistIndexWithFields(
            [Entry::class => 'title', Category::class => 'title'],
            CraftProvider::class,
            $this->fieldSectionSiteId(),
        );

        // Categories have no `live` status, so Craft answers them with nothing; the entries decide.
        $result = $this->searchIndex($index->handle, self::TERM, ['status' => 'live']);

        self::assertSame([$this->live->id], $this->idsOf($result));
    }

    public function testUnpublishedContentNeedsAnAdministrator(): void
    {
        $this->expectException(UnauthorizedQueryException::class);
        $this->search(['status' => 'disabled']);
    }

    public function testASignedInNonAdministratorIsRefusedToo(): void
    {
        $user = new User();
        $user->username = 'zqxwvisible';
        $user->admin = false;
        Craft::$app->getUser()->setIdentity($user);

        $this->expectException(UnauthorizedQueryException::class);
        $this->search(['status' => 'disabled']);
    }

    public function testAnAdministratorMaySearchUnpublishedContent(): void
    {
        Craft::$app->getUser()->setIdentity($this->adminUser());

        $result = $this->search(['status' => 'disabled']);

        self::assertSame([$this->hidden->id], $this->idsOf($result));
        self::assertSame(1, $result->total);
    }

    public function testCraftsOwnRefusalMakesTheSearchUnanswerable(): void
    {
        Craft::$app->getUser()->setIdentity($this->adminUser());
        $this->refuseViewing();

        // Dropping the result instead would leave the total describing a different set of results.
        $this->expectException(UnauthorizedQueryException::class);
        $this->search(['status' => 'disabled']);
    }

    public function testAStatusNoElementTypeHasIsRefused(): void
    {
        try {
            $this->search(['status' => 'bogus']);
            self::fail('A status Craft cannot answer should be refused, not quietly matched to nothing.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('status', $e->getErrors());
        }
    }

    public function testHydrationCannotResurrectContentTheSearchExcluded(): void
    {
        $index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            BareHitProvider::class,
            $this->fieldSectionSiteId(),
        );

        // An external provider may still hold an element Craft no longer publishes.
        BareHitProvider::$hits = [new SearchHit([
            'elementId' => (int)$this->hidden->id,
            'siteId' => (int)$this->hidden->siteId,
            'elementType' => Entry::class,
        ])];

        $result = $this->plugin()->getSearch()->search(SearchQuery::create($index->handle, self::TERM));

        self::assertSame([], $result->hits);
        self::assertSame(0, $result->total);
    }

    public function testHydrationLoadsWhatTheSearchDidAskFor(): void
    {
        $index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            BareHitProvider::class,
            $this->fieldSectionSiteId(),
        );

        BareHitProvider::$hits = [new SearchHit([
            'elementId' => (int)$this->live->id,
            'siteId' => (int)$this->live->siteId,
            'elementType' => Entry::class,
        ])];

        $result = $this->plugin()->getSearch()->search(SearchQuery::create($index->handle, self::TERM));

        self::assertCount(1, $result->hits);
        self::assertSame($this->live->id, $result->hits[0]->element?->id);
    }

    private function expiredPage(string $title): Entry
    {
        $entry = $this->createPage($title, postDate: new DateTime('-10 days'));
        $entry->expiryDate = new DateTime('-1 day');

        self::assertTrue(Craft::$app->getElements()->saveElement($entry));
        Craft::$app->getSearch()->indexElementAttributes($entry);

        return $entry;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function search(array $params = []): SearchResult
    {
        return $this->searchIndex($this->index->handle, self::TERM, $params);
    }
}
