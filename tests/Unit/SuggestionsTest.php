<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\models\ParsedQuery;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\services\Normalization;
use Tahadudhiya\SearchKit\services\QueryPipeline;
use Tahadudhiya\SearchKit\services\StopWords;
use Tahadudhiya\SearchKit\services\Suggestions;
use Tahadudhiya\SearchKit\Tests\Support\StubProvider;
use Tahadudhiya\SearchKit\Tests\Support\StubSynonyms;
use Tahadudhiya\SearchKit\Tests\Support\StubTerms;
use yii\caching\ArrayCache;

class SuggestionsTest extends TestCase
{
    private Suggestions $suggestions;
    private StubTerms $terms;
    private QueryPipeline $pipeline;
    private SearchIndex $index;

    protected function setUp(): void
    {
        $normalization = new Normalization();
        $normalization->language = 'en-US';

        $stopWords = new StopWords();
        $stopWords->setNormalization($normalization);

        $this->pipeline = new QueryPipeline();
        $this->pipeline->setNormalization($normalization);
        $this->pipeline->setStopWords($stopWords);
        $this->pipeline->setSynonyms(new StubSynonyms());

        $this->terms = new StubTerms();
        $this->terms->words = ['boots', 'boot', 'booking', 'winter', 'sale', 'snowboard', 'cat', 'bat', 'form', 'worm', 'apple', 'ample'];

        $this->suggestions = new Suggestions();
        $this->suggestions->setTerms($this->terms);
        $this->suggestions->setNormalization($normalization);
        $this->suggestions->setCache(new ArrayCache());

        $this->index = new SearchIndex([
            'id' => 1,
            'handle' => 'siteSearch',
            'provider' => CraftProvider::class,
        ]);
    }

    public function testCompletesTheLastWordAndKeepsTheRest(): void
    {
        self::assertSame(['winter boot', 'winter boots'], $this->suggestions->autocomplete($this->index, 'winter boo', 2));
    }

    public function testDoesNotOfferBackExactlyWhatWasTyped(): void
    {
        self::assertNotContains('boot', $this->suggestions->autocomplete($this->index, 'boot', 5));
    }

    public function testCompletesNothingForAWordTheIndexHasNeverSeen(): void
    {
        self::assertSame([], $this->suggestions->autocomplete($this->index, 'zebra', 5));
    }

    public function testCorrectsAMisspelledWordToTheClosestOneTheIndexHolds(): void
    {
        $corrected = $this->suggestions->correct($this->parse('bots'), $this->index);

        self::assertNotNull($corrected);
        self::assertTrue($corrected->corrected);
        self::assertSame('boots', $corrected->getTerms()[0]->text);
        self::assertSame('bots', $corrected->getTerms()[0]->original);
    }

    public function testLeavesAWordTheIndexAlreadyHoldsAlone(): void
    {
        self::assertNull($this->suggestions->correct($this->parse('winter'), $this->index));
    }

    public function testLeavesAWordTooShortToJudgeAlone(): void
    {
        self::assertNull($this->suggestions->correct($this->parse('bot'), $this->index));
    }

    public function testLeavesAWordNothingIsCloseEnoughToAlone(): void
    {
        self::assertNull($this->suggestions->correct($this->parse('bicycle'), $this->index));
    }

    public function testCorrectingCanBeTurnedOffForAnIndex(): void
    {
        $this->index->getSearchSettings()->typoTolerance = false;

        self::assertNull($this->suggestions->correct($this->parse('bots'), $this->index));
    }

    public function testSuggestsTheCorrectionAndTheWordsTheIndexDoesHold(): void
    {
        $suggestions = $this->suggestions->forQuery($this->parse('winter bots'), $this->index, null, 5);

        self::assertContains('winter boots', $suggestions);
        self::assertContains('winter', $suggestions);
    }

    public function testSuggestsCompletionsForAWordThatWasOnlyStarted(): void
    {
        self::assertContains('snowboard', $this->suggestions->forQuery($this->parse('snowbo'), $this->index, null, 5));
    }

    public function testNeverSuggestsTheQueryThatFoundNothing(): void
    {
        self::assertNotContains('winter', $this->suggestions->forQuery($this->parse('winter'), $this->index, null, 5));
    }

    public function testCountsEditsInCharactersRatherThanBytes(): void
    {
        self::assertSame(0, $this->suggestions->distance('café', 'café'));
        self::assertSame(1, $this->suggestions->distance('café', 'cafe'));
        self::assertSame(3, $this->suggestions->distance('kitten', 'sitting'));
        self::assertSame(3, $this->suggestions->distance('', 'abc'));
        self::assertSame(0, $this->suggestions->distance('', ''));
    }

    public function testCountsEveryKindOfSingleEditAsOne(): void
    {
        self::assertSame(1, $this->suggestions->distance('cat', 'bat'), 'first character');
        self::assertSame(1, $this->suggestions->distance('cat', 'car'), 'last character');
        self::assertSame(1, $this->suggestions->distance('cart', 'curt'), 'middle character');
        self::assertSame(1, $this->suggestions->distance('cat', 'cart'), 'insertion');
        self::assertSame(1, $this->suggestions->distance('cart', 'cat'), 'deletion');
        self::assertSame(1, $this->suggestions->distance('form', 'from'), 'transposition');
    }

    public function testStopsCountingOnceTheWordsAreFurtherApartThanAsked(): void
    {
        // The exact distance stops mattering past the limit, so anything beyond it reads the same.
        self::assertSame(2, $this->suggestions->distance('kitten', 'sitting', 1));
        self::assertSame(1, $this->suggestions->distance('cat', 'bat', 1));
    }

    public function testCorrectsAWordWhoseFirstCharacterIsWrong(): void
    {
        // The first character is no less correctable than any other, so “zorm” still reaches “form”.
        self::assertSame('form', $this->correctionOf('zorm'));
    }

    public function testCorrectsAcrossEveryPositionInTheWord(): void
    {
        self::assertSame('worm', $this->correctionOf('wormm'), 'insertion');
        self::assertSame('apple', $this->correctionOf('appl'), 'deletion');
        self::assertSame('apple', $this->correctionOf('applr'), 'last character');
        self::assertSame('ample', $this->correctionOf('amble'), 'middle character');
        self::assertSame('form', $this->correctionOf('from'), 'transposition');
    }

    public function testACandidateFarDownTheVocabularyIsStillFound(): void
    {
        // Nothing is cut off at an arbitrary number of candidates, so the one real correction wins
        // however many equally long words the index happens to hold.
        $this->terms->words = array_map(static fn(int $i) => 'wordz' . $i, range(1, 200));
        $this->terms->words[] = 'zebra';

        self::assertSame('zebra', $this->correctionOf('zebrx'));
    }

    public function testPrefersTheCloserOfTwoCandidates(): void
    {
        $this->terms->words = ['snowboard', 'snowboards'];

        self::assertSame('snowboard', $this->correctionOf('snowboord'));
    }

    public function testBreaksATieBetweenEquallyCloseWordsTheSameWayEveryTime(): void
    {
        $this->terms->words = ['hark', 'cark', 'bark'];

        // Shortest first, then alphabetical, so the same query always corrects to the same word.
        self::assertSame('bark', $this->correctionOf('zark'));
        self::assertSame('bark', $this->correctionOf('zark'));
    }

    public function testWillNotCorrectFurtherThanTheIndexAllows(): void
    {
        $this->terms->words = ['snowboard'];

        self::assertNull($this->suggestions->correct($this->parse('snowbaerd'), $this->index));

        $this->index->getSearchSettings()->typoMaxDistance = 2;

        self::assertSame('snowboard', $this->correctionOf('snowbaerd'));
    }

    public function testNeverCorrectsToAWordNobodyMayFind(): void
    {
        $this->terms->words = ['boots', 'bootz'];
        $this->terms->hidden = ['bootz'];

        // “bootz” is the same distance away, but no publicly searchable document uses it.
        self::assertSame('boots', $this->correctionOf('bootx'));
    }

    public function testAWordOnlyHiddenContentUsesIsNotTreatedAsOneTheIndexHolds(): void
    {
        $this->terms->words = ['winter', 'winger'];
        $this->terms->hidden = ['winter'];

        // The index technically knows the word, but nobody may find it, so it is still corrected.
        self::assertSame('winger', $this->correctionOf('winter'));
    }

    public function testCorrectsEveryMisspelledWordOfAQuery(): void
    {
        $corrected = $this->suggestions->correct($this->parse('bots wintr'), $this->index);

        self::assertNotNull($corrected);
        self::assertSame(['boots', 'winter'], array_map(static fn($t) => $t->text, $corrected->getTerms()));
    }

    public function testLeavesPhrasesAlternativesAndExclusionsAlone(): void
    {
        self::assertNull($this->suggestions->correct($this->parse('"bots sale"'), $this->index));
        self::assertNull($this->suggestions->correct($this->parse('bots OR zzzz'), $this->index));

        $corrected = $this->suggestions->correct($this->parse('winter -bots'), $this->index);

        self::assertNull($corrected, 'An exclusion is never widened into something else.');
    }

    public function testACorrectionIsFoundHoweverManyEquallyCloseWordsAreHidden(): void
    {
        $variants = $this->variantsOf('zqxbase');

        self::assertGreaterThan(50, count($variants), 'The run of hidden words has to be longer than any cap.');

        $this->terms->words = $variants;

        // Everything but the last one is hidden, so a cap on how many are checked would miss it.
        $visible = (string)array_pop($variants);
        $this->terms->hidden = $variants;

        self::assertSame($visible, $this->correctionOf('zqxbase'));
    }

    public function testCompletionsAreFoundHoweverManyEarlierOnesAreHidden(): void
    {
        $words = array_map(static fn(int $i) => sprintf('zqxauto%03d', $i), range(1, 100));
        $this->terms->words = $words;

        // Far more than any fixed over-read of the five that were asked for.
        $this->terms->hidden = array_slice($words, 0, 90);

        self::assertSame(array_slice($words, 90, 5), $this->suggestions->autocomplete($this->index, 'zqxauto', 5));
    }

    public function testStopsLookingForCompletionsOnceTheIndexRunsOut(): void
    {
        $this->terms->words = ['zqxonlyone'];
        $this->terms->hidden = ['zqxonlyone'];

        self::assertSame([], $this->suggestions->autocomplete($this->index, 'zqxonly', 5));
    }

    /**
     * Every word one substitution away from this one, which is the largest set of equally close
     * words an index could plausibly hold.
     *
     * @return string[]
     */
    private function variantsOf(string $word): array
    {
        $variants = [];
        $letters = range('a', 'z');

        foreach (range(3, mb_strlen($word) - 1) as $position) {
            foreach ($letters as $letter) {
                $variant = mb_substr($word, 0, $position) . $letter . mb_substr($word, $position + 1);

                if ($variant !== $word) {
                    $variants[$variant] = true;
                }
            }
        }

        $variants = array_keys($variants);
        sort($variants);

        return $variants;
    }

    public function testAutocompleteNeverOffersAWordNobodyMayFind(): void
    {
        $this->terms->hidden = ['boots'];

        $completions = $this->suggestions->autocomplete($this->index, 'boo', 5);

        self::assertContains('boot', $completions);
        self::assertNotContains('boots', $completions);
    }

    private function correctionOf(string $text): ?string
    {
        $corrected = $this->suggestions->correct($this->parse($text), $this->index);

        return $corrected?->getTerms()[0]->text;
    }

    private function parse(string $text): ParsedQuery
    {
        $provider = new StubProvider();
        $provider->supported = CraftProvider::capabilities();

        return $this->pipeline->parse(SearchQuery::create('siteSearch', $text), $this->index, $provider);
    }
}
