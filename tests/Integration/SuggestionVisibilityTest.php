<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Entry;
use DateTime;
use Tahadudhiya\SearchKit\errors\IndexDisabledException;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * Suggestions are read by anybody who can reach a search box, so nothing they offer may name
 * content that is not published. This exercises the public API rather than the dictionary itself.
 */
class SuggestionVisibilityTest extends ContentTestCase
{
    /** @var string A word no other content in the project can match. */
    private const TERM = 'zqxvisible';

    private SearchIndex $index;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->sectionSiteId(),
        );
    }

    public function testCompletesAWordFromPublishedContent(): void
    {
        $this->indexEntry(self::TERM . ' Zqxpublishedword');

        self::assertContains('zqxpublishedword', $this->autocomplete('zqxpublished'));
    }

    public function testNeverCompletesAWordOnlyDisabledContentUses(): void
    {
        $this->indexEntry(self::TERM . ' Zqxdisabledword', enabled: false);

        self::assertSame([], $this->autocomplete('zqxdisabled'));
    }

    public function testNeverCompletesAWordOnlyUnpostedContentUses(): void
    {
        $this->indexEntry(self::TERM . ' Zqxfutureword', postDate: new DateTime('+10 years'));

        self::assertSame([], $this->autocomplete('zqxfuture'));
    }

    public function testNeverCompletesAWordOnlyExpiredContentUses(): void
    {
        $entry = $this->indexEntry(self::TERM . ' Zqxexpiredword');
        $entry->expiryDate = new DateTime('-1 day');
        Craft::$app->getElements()->saveElement($entry);
        $this->drain();

        self::assertSame([], $this->autocomplete('zqxexpired'));
    }

    public function testNeverCompletesAWordOnlyADeletedElementUsed(): void
    {
        $entry = $this->indexEntry(self::TERM . ' Zqxdeletedword');

        self::assertContains('zqxdeletedword', $this->autocomplete('zqxdeleted'));

        Craft::$app->getElements()->deleteElement($entry);
        $this->drain();

        self::assertSame([], $this->autocomplete('zqxdeleted'));
    }

    public function testAnAdministratorIsOfferedNoMoreThanAnyoneElse(): void
    {
        $this->indexEntry(self::TERM . ' Zqxadminword', enabled: false);

        Craft::$app->getUser()->setIdentity($this->adminUser());

        // Suggestions describe what is published, whoever is asking. Unpublished content is
        // searched for explicitly, by status, not stumbled upon through a completion.
        self::assertSame([], $this->autocomplete('zqxadmin'));

        Craft::$app->getUser()->setIdentity(null);
    }

    public function testASearchThatFoundNothingNeverSuggestsHiddenContent(): void
    {
        $this->indexEntry(self::TERM . ' Zqxsecretword', enabled: false);

        $result = $this->plugin()->getSearch()->search(
            SearchQuery::create($this->index->handle, 'zqxsecretwrd'),
        );

        self::assertTrue($result->isEmpty());
        self::assertFalse($result->wasCorrected(), 'A hidden word is no correction.');

        foreach ($result->suggestions as $suggestion) {
            self::assertStringNotContainsString('zqxsecretword', $suggestion);
        }
    }

    public function testAMisspellingIsNeverCorrectedToAHiddenWord(): void
    {
        $this->indexEntry(self::TERM . ' Zqxcorrectme', enabled: false);

        $suggestions = $this->plugin()->getSearch()->suggest(
            SearchQuery::create($this->index->handle, 'zqxcorrectmm'),
        );

        self::assertNotContains('zqxcorrectme', $suggestions);
    }

    public function testADisabledIndexOffersNothingAtAll(): void
    {
        $this->indexEntry(self::TERM . ' Zqxoffword');

        $this->index->enabled = false;
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($this->index));

        $this->expectException(IndexDisabledException::class);
        $this->autocomplete('zqxoff');
    }

    public function testAnIndexOffersOnlyItsOwnWords(): void
    {
        $other = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class, $this->sectionSiteId());
        $this->indexEntry(self::TERM . ' Zqxownword');

        $completions = $this->plugin()->getSearch()->autocomplete(
            SearchQuery::create($other->handle, 'zqxown'),
        );

        self::assertSame([], $completions, 'The other index has indexed nothing.');
    }

    public function testANewWordIsOfferedWithoutWaitingForACacheToExpire(): void
    {
        self::assertSame([], $this->autocomplete('zqxfresh'));

        $this->indexEntry(self::TERM . ' Zqxfreshword');

        // The first lookup cached an empty answer; indexing has to make that answer wrong at once.
        self::assertContains('zqxfreshword', $this->autocomplete('zqxfresh'));
    }

    public function testAWordThatDisappearsStopsBeingOfferedAtOnce(): void
    {
        $entry = $this->indexEntry(self::TERM . ' Zqxstaleword');

        self::assertContains('zqxstaleword', $this->autocomplete('zqxstale'));

        $entry->title = self::TERM . ' Zqxreplacement';
        Craft::$app->getElements()->saveElement($entry);
        $this->drain();

        self::assertSame([], $this->autocomplete('zqxstale'));
        self::assertContains('zqxreplacement', $this->autocomplete('zqxreplace'));
    }

    public function testCompletionsKeepEverythingAlreadyTyped(): void
    {
        $this->indexEntry(self::TERM . ' Zqxkeptword');

        $completions = $this->plugin()->getSearch()->autocomplete(
            SearchQuery::create($this->index->handle, self::TERM . ' zqxkept'),
        );

        self::assertContains(self::TERM . ' zqxkeptword', $completions);
    }

    public function testOffersNothingForTextThatIsNotAWord(): void
    {
        $this->indexEntry(self::TERM . ' Zqxanything');

        self::assertSame([], $this->autocomplete(''));
        self::assertSame([], $this->autocomplete('   '));
        self::assertSame([], $this->autocomplete('!!!'));
    }

    /**
     * @return string[]
     */
    private function autocomplete(string $text): array
    {
        return $this->plugin()->getSearch()->autocomplete(
            SearchQuery::create($this->index->handle, $text),
        );
    }

    private function adminUser(): \craft\elements\User
    {
        $admin = \craft\elements\User::find()->admin(true)->status(null)->one();

        if ($admin === null) {
            self::markTestSkipped('This project has no admin account.');
        }

        return $admin;
    }

    private function indexEntry(string $title, bool $enabled = true, ?DateTime $postDate = null): Entry
    {
        $entry = $this->createEntry($title);

        if (!$enabled || $postDate !== null) {
            $entry->enabled = $enabled;
            $entry->postDate = $postDate ?? $entry->postDate;
            Craft::$app->getElements()->saveElement($entry);
        }

        $this->drain();

        return $entry;
    }

    private function drain(): void
    {
        $this->plugin()->getIndexing()->processPending($this->index);
    }
}
