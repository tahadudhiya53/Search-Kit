<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\elements\Entry;
use Tahadudhiya\SearchKit\enums\PartialMatchMode;
use Tahadudhiya\SearchKit\enums\SynonymType;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\models\Synonym;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * Runs real searches over real content, so what it asserts about operators, synonyms, corrections
 * and suggestions is what a visitor would actually get.
 */
class SearchExperienceTest extends ContentTestCase
{
    /** @var string A word no other content in the project can match. */
    private const TERM = 'zqxglacier';

    private SearchIndex $index;

    /** @var Synonym[] */
    private array $createdSynonyms = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->sectionSiteId(),
        );

        foreach (['Zqxglacier Winter Boots', 'Zqxglacier Summer Sandals', 'Zqxglacier Winter Jacket'] as $title) {
            $this->createEntry($title);
        }

        // Indexing is what fills both Craft's keywords and the words behind suggestions.
        $this->plugin()->getIndexing()->processPending($this->index);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdSynonyms as $synonym) {
            $this->plugin()->getSynonyms()->deleteSynonym($synonym);
        }

        $this->createdSynonyms = [];

        parent::tearDown();
    }

    public function testFindsEverythingIndexedForTheTerm(): void
    {
        self::assertSame(3, $this->search(self::TERM)->total);
    }

    public function testAPhraseMatchesOnlyWhereItsWordsAppearTogether(): void
    {
        self::assertSame(1, $this->search(self::TERM . ' "winter boots"')->total);
        self::assertSame(0, $this->search(self::TERM . ' "boots winter"')->total);
    }

    public function testAnExclusionRulesResultsOut(): void
    {
        $result = $this->search(self::TERM . ' -winter');

        self::assertSame(1, $result->total);
        self::assertStringContainsString('Sandals', (string)$result->hits[0]->element?->title);
    }

    public function testAlternationAcceptsEitherTerm(): void
    {
        self::assertSame(2, $this->search(self::TERM . ' sandals OR jacket')->total);
    }

    public function testPartialMatchingFindsTheStartOfAWord(): void
    {
        self::assertSame(1, $this->search(self::TERM . ' boo')->total);

        $this->useSettings(static function($settings) {
            $settings->partialMatching = PartialMatchMode::Off;
        });

        self::assertSame(0, $this->search(self::TERM . ' boo')->total);
    }

    public function testAWildcardAsksForPartialMatchingWhateverTheIndexIsSetTo(): void
    {
        $this->useSettings(static function($settings) {
            $settings->partialMatching = PartialMatchMode::Off;
        });

        self::assertSame(1, $this->search(self::TERM . ' boo*')->total);
    }

    public function testAQuotedWordIsMatchedWholeRatherThanAsAPrefix(): void
    {
        self::assertSame(1, $this->search(self::TERM . ' boots')->total);
        self::assertSame(0, $this->search(self::TERM . ' "boo"')->total);
    }

    public function testStopWordsDoNotStopAQueryFindingAnything(): void
    {
        self::assertSame(1, $this->search('the ' . self::TERM . ' of boots')->total);
    }

    public function testASynonymFindsWhatTheIndexActuallyHolds(): void
    {
        $this->createSynonym(['boots', 'zqxfootwear']);

        self::assertSame(1, $this->search(self::TERM . ' zqxfootwear')->total);
    }

    public function testAOneWaySynonymOnlyExpandsInOneDirection(): void
    {
        $this->createSynonym(['boots'], ['sandals'], SynonymType::OneWay);

        // “boots” also finds the sandals, but “sandals” never finds the boots.
        self::assertSame(2, $this->search(self::TERM . ' boots')->total);
        self::assertSame(1, $this->search(self::TERM . ' sandals')->total);
    }

    public function testSynonymsCanBeTurnedOffForAnIndex(): void
    {
        $this->createSynonym(['boots', 'zqxfootwear']);

        $this->useSettings(static function($settings) {
            $settings->synonyms = false;
        });

        self::assertSame(0, $this->search(self::TERM . ' zqxfootwear')->total);
    }

    public function testCorrectsAMisspellingAndSaysSo(): void
    {
        $result = $this->search(self::TERM . ' bots');

        self::assertSame(1, $result->total);
        self::assertTrue($result->wasCorrected());
        self::assertStringContainsString('boots', (string)$result->correctedText);
    }

    public function testLeavesASearchThatFoundNothingAloneWhenNoCorrectionHelps(): void
    {
        $result = $this->search(self::TERM . ' zzqqxxnothing');

        self::assertTrue($result->isEmpty());
        self::assertFalse($result->wasCorrected());
    }

    public function testCorrectingCanBeTurnedOffForAnIndex(): void
    {
        $this->useSettings(static function($settings) {
            $settings->typoTolerance = false;
        });

        self::assertSame(0, $this->search(self::TERM . ' bots')->total);
    }

    public function testASearchThatFoundNothingOffersSomethingElseToTry(): void
    {
        $result = $this->search(self::TERM . ' zzqqxxnothing');

        self::assertTrue($result->hasSuggestions());
        self::assertContains(self::TERM, $result->suggestions);
    }

    public function testCompletesWhatHasBeenTypedSoFar(): void
    {
        $completions = $this->plugin()->getSearch()->autocomplete(
            SearchQuery::create($this->index->handle, self::TERM . ' boo'),
        );

        self::assertContains(self::TERM . ' boots', $completions);
    }

    public function testTheWordsBehindSuggestionsComeFromIndexedContent(): void
    {
        self::assertTrue($this->holdsWord('boots'));
        self::assertTrue($this->holdsWord(self::TERM));
        self::assertFalse($this->holdsWord('zzqqxxnothing'));
    }

    public function testARebuildRebuildsTheWordsFromTheContentItself(): void
    {
        $this->plugin()->getIndexing()->rebuild($this->index);

        self::assertTrue($this->holdsWord('boots'));
        self::assertTrue($this->holdsWord(self::TERM));
    }

    public function testRefusesAnExclusionOnEitherSideOfAlternation(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->search(self::TERM . ' boots OR -leather');
    }

    public function testRefusesASearchWithNothingToLookFor(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->search('-boots');
    }

    public function testMarksASynonymWhereItMatched(): void
    {
        $this->createSynonym(['boots', 'zqxfootwear']);

        $result = $this->plugin()->getSearch()->search(
            SearchQuery::create($this->index->handle, self::TERM . ' zqxfootwear', ['highlight' => true]),
        );

        self::assertSame(1, $result->total);
        self::assertStringContainsString('<mark>Boots</mark>', (string)$result->hits[0]->getHighlight());
    }

    public function testMarksACorrectedWordWhereItMatched(): void
    {
        $result = $this->plugin()->getSearch()->search(
            SearchQuery::create($this->index->handle, self::TERM . ' bots', ['highlight' => true]),
        );

        self::assertTrue($result->wasCorrected());
        self::assertStringContainsString('<mark>Boots</mark>', (string)$result->hits[0]->getHighlight());
    }

    public function testNeverMarksAnExcludedWord(): void
    {
        $result = $this->plugin()->getSearch()->search(
            SearchQuery::create($this->index->handle, self::TERM . ' -leather', ['highlight' => true]),
        );

        foreach ($result->hits as $hit) {
            self::assertStringNotContainsString('<mark>leather', mb_strtolower((string)$hit->getHighlight()));
        }
    }

    public function testCompletionsRefuseASiteTheIndexDoesNotCover(): void
    {
        $outside = $this->aSiteOtherThan($this->sectionSiteId());

        $this->expectException(InvalidQueryException::class);
        $this->plugin()->getSearch()->autocomplete(
            SearchQuery::create($this->index->handle, 'boo', ['site' => $outside]),
        );
    }

    public function testTheResultCarriesTheTermsItWasProducedFrom(): void
    {
        $result = $this->search(self::TERM . ' "winter boots"');

        self::assertNotNull($result->parsedQuery);
        self::assertTrue($result->parsedQuery->hasPhrases());
        self::assertContains('winter boots', $result->parsedQuery->getTokens());
    }

    /**
     * @param callable(\Tahadudhiya\SearchKit\models\SearchSettings):void $change
     */
    private function useSettings(callable $change): void
    {
        $change($this->index->getSearchSettings());
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($this->index));
    }

    /**
     * @param string[] $terms
     * @param string[] $replacements
     */
    private function createSynonym(array $terms, array $replacements = [], SynonymType $type = SynonymType::TwoWay): Synonym
    {
        $synonym = new Synonym([
            'indexId' => $this->index->id,
            'type' => $type,
            'terms' => $terms,
            'replacements' => $replacements,
        ]);

        self::assertTrue(
            $this->plugin()->getSynonyms()->saveSynonym($synonym),
            implode(' ', $synonym->getErrorSummary(true)),
        );

        $this->createdSynonyms[] = $synonym;

        return $synonym;
    }

    private function holdsWord(string $word): bool
    {
        return $this->plugin()->getTerms()->isSearchable((int)$this->index->id, $this->sectionSiteId(), $word);
    }

    private function search(string $text): SearchResult
    {
        return $this->plugin()->getSearch()->search(SearchQuery::create($this->index->handle, $text));
    }
}
