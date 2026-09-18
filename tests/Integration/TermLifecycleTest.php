<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Entry;
use DateTime;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\services\Terms;
use Tahadudhiya\SearchKit\Tests\Support\FailingTerms;
use Tahadudhiya\SearchKit\Tests\Support\RecordingProvider;

/**
 * The words an index holds have to follow the content they were read from: a word disappears when
 * the last document using it stops using it, and no word may name content nobody is allowed to find.
 */
class TermLifecycleTest extends ContentTestCase
{
    private SearchIndex $index;

    protected function tearDown(): void
    {
        // Indexing is a shared component; a test that swapped its dictionary puts it back.
        FailingTerms::$failing = true;
        $this->plugin()->getIndexing()->setTerms(new Terms());

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->sectionSiteId(),
        );
    }

    public function testAnIndexedDocumentContributesItsWords(): void
    {
        $this->indexEntry('Zqxalpha Zqxbeta');

        self::assertTrue($this->holds('zqxalpha'));
        self::assertTrue($this->holds('zqxbeta'));
    }

    public function testAWordTheDocumentNoLongerUsesDisappears(): void
    {
        $entry = $this->indexEntry('Zqxalpha Zqxbeta');

        $this->retitle($entry, 'Zqxalpha Zqxgamma');

        self::assertTrue($this->holds('zqxalpha'), 'A word the document still uses stays.');
        self::assertTrue($this->holds('zqxgamma'), 'A word the document now uses appears.');
        self::assertFalse($this->holds('zqxbeta'), 'A word the document dropped goes with it.');
    }

    public function testAWordAnotherDocumentStillUsesSurvives(): void
    {
        $first = $this->indexEntry('Zqxshared Zqxonlyfirst');
        $this->indexEntry('Zqxshared Zqxonlysecond');

        Craft::$app->getElements()->deleteElement($first);
        $this->drain();

        self::assertTrue($this->holds('zqxshared'), 'The other document still uses it.');
        self::assertFalse($this->holds('zqxonlyfirst'), 'Nothing uses this one any more.');
        self::assertTrue($this->holds('zqxonlysecond'));
    }

    public function testTheLastDocumentUsingAWordTakesItAway(): void
    {
        $first = $this->indexEntry('Zqxshared Zqxonlyfirst');
        $second = $this->indexEntry('Zqxshared Zqxonlysecond');

        Craft::$app->getElements()->deleteElement($first);
        Craft::$app->getElements()->deleteElement($second);
        $this->drain();

        self::assertFalse($this->holds('zqxshared'));
    }

    public function testARestoredDocumentBringsItsWordsBack(): void
    {
        $entry = $this->indexEntry('Zqxrestored Zqxword');

        Craft::$app->getElements()->deleteElement($entry);
        $this->drain();

        self::assertFalse($this->holds('zqxrestored'));

        Craft::$app->getElements()->restoreElement($entry);
        $this->drain();

        self::assertTrue($this->holds('zqxrestored'));
    }

    public function testIndexingThatFailedContributesNoWords(): void
    {
        $recording = $this->persistIndexWithFields([Entry::class => 'title'], RecordingProvider::class, $this->sectionSiteId());
        $entry = $this->createEntry('Zqxfailed Zqxword');

        RecordingProvider::$indexingFailure = new ProviderException('The provider refused this document.');
        $this->plugin()->getIndexing()->processPending($recording);

        // Nothing the provider never accepted may be described as something the index holds.
        self::assertSame(0, (new Terms())->countForIndex((int)$recording->id));
        self::assertNotNull($entry->id);
    }

    public function testAFailedReindexLeavesTheWordsTheDocumentAlreadyHad(): void
    {
        $recording = $this->persistIndexWithFields([Entry::class => 'title'], RecordingProvider::class, $this->sectionSiteId());
        $entry = $this->createEntry('Zqxkept Zqxword');
        $this->plugin()->getIndexing()->processPending($recording);

        $terms = $this->plugin()->getTerms();
        self::assertTrue($terms->isSearchable((int)$recording->id, $this->sectionSiteId(), 'zqxkept'));

        RecordingProvider::$indexingFailure = new ProviderException('The provider refused this document.');
        $entry->title = 'Zqxreplaced Zqxword';
        Craft::$app->getElements()->saveElement($entry);
        $this->plugin()->getIndexing()->processPending($recording);

        // The provider still holds the old document, so the words have to describe that one.
        self::assertTrue($terms->isSearchable((int)$recording->id, $this->sectionSiteId(), 'zqxkept'));
        self::assertFalse($terms->isSearchable((int)$recording->id, $this->sectionSiteId(), 'zqxreplaced'));
    }

    public function testAWordIsFoundHoweverManyHiddenDocumentsUseItFirst(): void
    {
        // More hidden documents than any batch or sample size, with the only published one last.
        for ($i = 0; $i < 30; $i++) {
            $this->indexEntry('Zqxcrowded Hidden ' . $i, enabled: false);
        }

        $this->indexEntry('Zqxcrowded Published');

        self::assertTrue($this->holds('zqxcrowded'), 'A published document uses it, however late.');
    }

    public function testWordsThatFailedToBeRecordedAreRetriedRatherThanSettled(): void
    {
        $indexing = $this->plugin()->getIndexing();
        $entry = $this->createEntry('Zqxretried Zqxword');

        FailingTerms::$failing = true;
        $indexing->setTerms(new FailingTerms());

        $failed = $indexing->processPending($this->index);

        // The provider took the document, but the words do not describe it, so nothing is settled.
        self::assertSame(1, $failed->failed);
        self::assertSame(0, $failed->processed);
        self::assertSame(1, $this->pendingCount($this->index), 'The work is still owed.');

        FailingTerms::$failing = false;
        $indexing->setTerms(new Terms());

        $recovered = $indexing->processPending($this->index);

        self::assertSame(1, $recovered->processed);
        self::assertSame(0, $this->pendingCount($this->index));
        self::assertTrue($this->holds('zqxretried'), 'The retry put the words right.');
        self::assertNotNull($entry->id);
    }

    public function testAStaleWorkerCannotPutADeletedDocumentsWordsBack(): void
    {
        $entry = $this->indexEntry('Zqxrace Zqxword');

        self::assertTrue($this->holds('zqxrace'));

        // Stands in for a worker that loaded the element just before it was deleted, and only got
        // round to writing its words afterwards.
        Craft::$app->getElements()->deleteElement($entry);

        $this->plugin()->getTerms()->record(
            (int)$this->index->id,
            (int)$entry->id,
            $this->sectionSiteId(),
            Entry::class,
            ['zqxrace', 'zqxword'],
        );

        self::assertSame(0, $this->rawCount(), 'A document that has gone contributes nothing.');
    }

    public function testDeletingAnIndexTakesItsWordsWithIt(): void
    {
        $this->indexEntry('Zqxcascade Zqxword');
        $indexId = (int)$this->index->id;

        self::assertGreaterThan(0, (new Terms())->countForIndex($indexId));

        $this->plugin()->getIndexes()->deleteIndex($this->index);

        self::assertSame(0, (new Terms())->countForIndex($indexId));
    }

    public function testAWordOnlyUnpublishedContentUsesIsNeverOffered(): void
    {
        $this->indexEntry('Zqxhiddenword Zqxpublished', enabled: false);

        // The row is there, because the content is indexed; it just names nothing anyone may find.
        self::assertSame(2, $this->rawCount());
        self::assertFalse($this->holds('zqxhiddenword'));
    }

    public function testAWordIsOfferedAgainOnceItsContentIsPublished(): void
    {
        $entry = $this->indexEntry('Zqxtoggle Zqxword', enabled: false);

        self::assertFalse($this->holds('zqxtoggle'));

        $entry->enabled = true;
        Craft::$app->getElements()->saveElement($entry);
        $this->drain();

        self::assertTrue($this->holds('zqxtoggle'));
    }

    public function testAWordOnlyFutureContentUsesIsNeverOffered(): void
    {
        $this->indexEntry('Zqxfuture Zqxword', postDate: new DateTime('+10 years'));

        self::assertFalse($this->holds('zqxfuture'), 'An entry that is not posted yet is not published.');
    }

    public function testAWordOnlyExpiredContentUsesIsNeverOffered(): void
    {
        $entry = $this->indexEntry('Zqxexpired Zqxword');
        $entry->expiryDate = new DateTime('-1 day');
        Craft::$app->getElements()->saveElement($entry);
        $this->drain();

        self::assertFalse($this->holds('zqxexpired'));
    }

    public function testAWordOnlyADeletedElementUsedIsNeverOffered(): void
    {
        $entry = $this->indexEntry('Zqxgone Zqxword');
        $terms = $this->plugin()->getTerms();

        // Even if the rows outlived the element, the word still names nothing anyone may find.
        Craft::$app->getElements()->deleteElement($entry);

        self::assertFalse($terms->isSearchable((int)$this->index->id, $this->sectionSiteId(), 'zqxgone'));
    }

    public function testADraftNeverContributesWords(): void
    {
        $entry = $this->indexEntry('Zqxdraftparent Zqxword');
        $draft = Craft::$app->getDrafts()->createDraft($entry, (int)$this->adminUserId(), 'Zqxdraftonly');
        $this->drain();

        self::assertFalse($this->holds('zqxdraftonly'));
        self::assertTrue($this->holds('zqxdraftparent'));
        self::assertNotNull($draft->id);
    }

    public function testEachIndexHoldsItsOwnWords(): void
    {
        $other = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class, $this->sectionSiteId());
        $this->indexEntry('Zqxperindex Zqxword');

        $terms = $this->plugin()->getTerms();

        self::assertTrue($terms->isSearchable((int)$this->index->id, $this->sectionSiteId(), 'zqxperindex'));
        self::assertFalse(
            $terms->isSearchable((int)$other->id, $this->sectionSiteId(), 'zqxperindex'),
            'The other index was never asked to index anything.',
        );
    }

    public function testADisabledIndexIsNeverWrittenTo(): void
    {
        $this->index->enabled = false;
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($this->index));

        $this->createEntry('Zqxdisabledindex Zqxword');
        $this->plugin()->getIndexing()->processPending($this->index);

        self::assertSame(0, $this->rawCount());
    }

    public function testAnIndexNoLongerConfiguredForAnElementForgetsItsWords(): void
    {
        $this->indexEntry('Zqxreconfigured Zqxword');

        self::assertTrue($this->holds('zqxreconfigured'));

        // The index still covers entries, but no longer searches anything they carry.
        self::assertTrue($this->plugin()->getSearchableFields()->saveFieldsForIndex($this->index, []));
        $this->plugin()->getIndexing()->rebuild($this->index);

        self::assertSame(0, $this->rawCount());
    }

    private function adminUserId(): int
    {
        $admin = \craft\elements\User::find()->admin(true)->status(null)->one();

        if ($admin === null) {
            self::markTestSkipped('This project has no admin account.');
        }

        return (int)$admin->id;
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

    private function retitle(Entry $entry, string $title): void
    {
        $entry->title = $title;
        Craft::$app->getElements()->saveElement($entry);
        $this->drain();
    }

    private function drain(): void
    {
        $this->plugin()->getIndexing()->processPending($this->index);
    }

    private function holds(string $word): bool
    {
        return $this->plugin()->getTerms()->isSearchable((int)$this->index->id, $this->sectionSiteId(), $word);
    }

    private function rawCount(): int
    {
        return (new Terms())->countForIndex((int)$this->index->id);
    }
}
