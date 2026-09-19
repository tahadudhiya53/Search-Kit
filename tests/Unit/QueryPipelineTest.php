<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\enums\PartialMatchMode;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\models\ParsedQuery;
use Tahadudhiya\SearchKit\models\QueryTerm;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchSettings;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\services\Normalization;
use Tahadudhiya\SearchKit\services\QueryPipeline;
use Tahadudhiya\SearchKit\services\StopWords;
use Tahadudhiya\SearchKit\Tests\Support\StubProvider;
use Tahadudhiya\SearchKit\Tests\Support\StubSynonyms;

class QueryPipelineTest extends TestCase
{
    private QueryPipeline $pipeline;
    private StubSynonyms $synonyms;
    private SearchIndex $index;
    private StubProvider $provider;

    protected function setUp(): void
    {
        $normalization = new Normalization();
        $normalization->language = 'en-US';

        $stopWords = new StopWords();
        $stopWords->setNormalization($normalization);

        $this->synonyms = new StubSynonyms();

        $this->pipeline = new QueryPipeline();
        $this->pipeline->setNormalization($normalization);
        $this->pipeline->setStopWords($stopWords);
        $this->pipeline->setSynonyms($this->synonyms);

        $this->index = new SearchIndex([
            'id' => 1,
            'handle' => 'siteSearch',
            'provider' => CraftProvider::class,
        ]);

        $this->provider = new StubProvider();
        $this->provider->supported = CraftProvider::capabilities();
    }

    public function testNormalizesAndTokenizesWhatWasTyped(): void
    {
        $parsed = $this->parse('  Winter   BOOTS!  ');

        self::assertSame('winter boots', $parsed->normalized);
        self::assertSame(['winter', 'boots'], array_map(static fn(QueryTerm $t) => $t->text, $parsed->getTerms()));
    }

    public function testFoldsAccentsTheWayIndexedContentIs(): void
    {
        self::assertSame(['cafe'], $this->parse('Café')->getTokens());
    }

    public function testReadsAPhrase(): void
    {
        $parsed = $this->parse('"winter boots" sale');
        [$phrase, $word] = $parsed->getTerms();

        self::assertTrue($phrase->phrase);
        self::assertSame('winter boots', $phrase->text);
        self::assertSame(PartialMatchMode::Off, $phrase->partial);
        self::assertFalse($word->phrase);
    }

    public function testAQuotedWordIsMatchedWholeRatherThanAsAPrefix(): void
    {
        $parsed = $this->parse('"boot"');

        self::assertTrue($parsed->getTerms()[0]->exact);
        self::assertSame(PartialMatchMode::Off, $parsed->getTerms()[0]->partial);
    }

    public function testAWordThatNormalizesIntoSeveralIsKeptTogether(): void
    {
        $parsed = $this->parse('e-mail');

        self::assertSame('e mail', $parsed->getTerms()[0]->text);
        self::assertTrue($parsed->getTerms()[0]->phrase);
    }

    public function testReadsAnExclusion(): void
    {
        $parsed = $this->parse('boots -leather');

        self::assertSame(['boots'], array_map(static fn(QueryTerm $t) => $t->text, $parsed->getRequiredTerms()));
        self::assertSame(['leather'], array_map(static fn(QueryTerm $t) => $t->text, $parsed->getExcludedTerms()));
        self::assertSame(PartialMatchMode::Off, $parsed->getExcludedTerms()[0]->partial);
    }

    public function testReadsAlternation(): void
    {
        $parsed = $this->parse('boots OR shoes OR sandals');

        self::assertCount(1, $parsed->getTerms());
        self::assertSame(['boots', 'shoes', 'sandals'], $parsed->getTerms()[0]->getTexts());
    }

    public function testAlternationWithNothingOnOneSideIsDropped(): void
    {
        self::assertSame(['boots'], $this->parse('OR boots')->getTokens());
        self::assertSame(['boots'], $this->parse('boots OR')->getTokens());
    }

    public function testRefusesAnExclusionOnEitherSideOfAlternation(): void
    {
        // Either reading of “foo OR -bar” searches for something other than what was written.
        foreach (['boots OR -leather', '-boots OR leather'] as $text) {
            try {
                $this->parse($text);
                self::fail($text . ' should not be read as anything.');
            } catch (InvalidQueryException $e) {
                self::assertArrayHasKey('text', $e->getErrors());
            }
        }
    }

    public function testAlternationKeepsEachAlternativeMatchingAsItWasWritten(): void
    {
        $parsed = $this->parse('boot* OR "winter sale"');
        [$first, $second] = $parsed->getTerms()[0]->getVariants();

        self::assertSame(PartialMatchMode::Prefix, $first->partial);
        self::assertTrue($second->phrase);
        self::assertSame('winter sale', $second->text);
    }

    public function testAlternationAndExclusionCanStillBeUsedInOneQuery(): void
    {
        $parsed = $this->parse('boots OR shoes -leather');

        self::assertSame(['boots', 'shoes'], $parsed->getTerms()[0]->getTexts());
        self::assertSame(['leather'], array_map(static fn(QueryTerm $t) => $t->text, $parsed->getExcludedTerms()));
    }

    public function testAlternationIsOnlyTheWordORInCapitals(): void
    {
        // Lowercase “or” is a word somebody searched for, and it is a stop word at that.
        $parsed = $this->parse('boots or shoes');

        self::assertCount(2, $parsed->getTerms());
        self::assertFalse($parsed->hasAlternatives());
    }

    public function testRepeatedAlternationKeepsOneTerm(): void
    {
        self::assertSame(['boots', 'shoes'], $this->parse('boots OR OR shoes')->getTerms()[0]->getTexts());
    }

    public function testAnAlternativeRepeatingTheTermIsNotAddedTwice(): void
    {
        self::assertSame(['boots'], $this->parse('boots OR boots')->getTerms()[0]->getTexts());
    }

    public function testMalformedOperatorsAreReadAsTextRatherThanFailing(): void
    {
        self::assertSame(['winter', 'boots'], $this->parse('"winter boots')->getTokens());
        self::assertSame(['boots'], $this->parse('- boots')->getTokens());
        self::assertSame(['boots'], $this->parse('** boots **')->getTokens());
        self::assertSame(['boots'], $this->parse('"" boots')->getTokens());
    }

    public function testConfiguredMatchingIsNotWrittenBackAsSomethingTheUserTyped(): void
    {
        // The index matches on the start of a word, but nobody typed an asterisk.
        self::assertSame('winter boots', $this->parse('winter boots')->getText());
        self::assertSame('boots*', $this->parse('boots*')->getText());
    }

    public function testReadsWildcards(): void
    {
        self::assertSame(PartialMatchMode::Prefix, $this->parse('boot*')->getTerms()[0]->partial);
        self::assertSame(PartialMatchMode::Substring, $this->parse('*boot*')->getTerms()[0]->partial);
        self::assertSame('boot', $this->parse('*boot*')->getTerms()[0]->text);
    }

    public function testOperatorsCanBeTurnedOffForAnIndex(): void
    {
        $this->index->getSearchSettings()->operators = false;
        $parsed = $this->parse('"winter boots" -leather');

        self::assertSame(['winter', 'boots', 'leather'], $parsed->getTokens());
        self::assertFalse($parsed->hasExclusions());
        self::assertFalse($parsed->hasPhrases());
    }

    public function testDropsStopWords(): void
    {
        $parsed = $this->parse('the history of the boot');

        self::assertSame(['history', 'boot'], $parsed->getTokens());
        self::assertContains('the', $parsed->removedStopWords);
    }

    public function testAQueryOfNothingButStopWordsIsLeftAlone(): void
    {
        $parsed = $this->parse('the and of');

        self::assertSame(['the', 'and', 'of'], $parsed->getTokens());
        self::assertSame([], $parsed->removedStopWords);
    }

    public function testStopWordsCanBeTurnedOffAndAddedTo(): void
    {
        $settings = $this->index->getSearchSettings();
        $settings->customStopWords = ['Boots'];

        self::assertSame(['winter'], $this->parse('winter boots')->getTokens());

        $settings->stopWords = false;

        self::assertSame(['winter', 'boots'], $this->parse('winter boots')->getTokens());
    }

    public function testAppliesTheIndexMatchingModeToWordsNoOperatorDecided(): void
    {
        $this->index->getSearchSettings()->partialMatching = PartialMatchMode::Substring;
        $parsed = $this->parse('boots ok');

        self::assertSame(PartialMatchMode::Substring, $parsed->getTerms()[0]->partial);

        // Too short to match on part of itself, so it stays a whole word.
        self::assertSame(PartialMatchMode::Off, $parsed->getTerms()[1]->partial);
    }

    public function testExpandsSynonyms(): void
    {
        $this->synonyms->expansions = ['boots' => ['footwear', 'winter shoes']];
        $parsed = $this->parse('boots');

        self::assertSame(['boots', 'footwear', 'winter shoes'], $parsed->getTerms()[0]->getTexts());
        self::assertSame(['boots', 'footwear', 'winter shoes'], $parsed->getTokens());
    }

    public function testSynonymsNeverWidenAnExclusion(): void
    {
        $this->synonyms->expansions = ['leather' => ['suede']];

        $excluded = $this->parse('boots -leather')->getExcludedTerms();

        // Widening what a search rules out would rule out more than was asked for.
        self::assertSame(['leather'], $excluded[0]->getTexts());
    }

    public function testSynonymsThatPointAtEachOtherDoNotExpandForever(): void
    {
        $this->synonyms->expansions = ['boots' => ['footwear'], 'footwear' => ['boots']];

        self::assertSame(['boots', 'footwear'], $this->parse('boots')->getTerms()[0]->getTexts());
    }

    public function testASynonymIsMatchedTheSameWayTheWordItStandsInForIs(): void
    {
        $this->synonyms->expansions = ['boots' => ['footwear']];

        [$term, $synonym] = $this->parse('boots')->getTerms()[0]->getVariants();

        self::assertSame($term->partial, $synonym->partial);
    }

    public function testOnlySoManySynonymsAreEverAppliedToOneTerm(): void
    {
        $this->synonyms->expansions = ['boots' => array_map(static fn(int $i) => 'alias' . $i, range(1, 40))];

        // A group large enough to pass this is a configuration mistake, not a query worth running.
        self::assertLessThanOrEqual(21, count($this->parse('boots')->getTerms()[0]->getTexts()));
    }

    public function testSynonymsCanBeTurnedOffForAnIndex(): void
    {
        $this->synonyms->expansions = ['boots' => ['footwear']];
        $this->index->getSearchSettings()->synonyms = false;

        self::assertSame(['boots'], $this->parse('boots')->getTokens());
    }

    public function testAProviderThatCannotOfferAlternativesSimplyGoesWithoutSynonyms(): void
    {
        $this->synonyms->expansions = ['boots' => ['footwear']];
        $this->provider->supported = [ProviderCapability::Search];

        self::assertSame(['boots'], $this->parse('boots')->getTokens());
    }

    public function testRefusesAnOperatorTheProviderCannotHonour(): void
    {
        $this->provider->supported = [ProviderCapability::Search];

        $this->expectException(UnsupportedCapabilityException::class);
        $this->parse('"winter boots"');
    }

    public function testAQueryOfOnlyExclusionsHasNothingToLookFor(): void
    {
        self::assertTrue($this->parse('-boots')->isEmpty());
    }

    public function testWritesTheQueryBackOutForShowingToAUser(): void
    {
        self::assertSame('boots* -leather "winter sale"', $this->parse('boots* -leather "winter sale"')->getText());
    }

    public function testStopWordsAreNotTakenOutOfAPhrase(): void
    {
        $parsed = $this->parse('"the who" boots');

        // A phrase means those words in that order; dropping one would search for something else.
        self::assertSame('the who', $parsed->getTerms()[0]->text);
        self::assertSame([], $parsed->removedStopWords);
    }

    public function testStopWordsAreNotTakenOutOfAnExclusionOrAnAlternation(): void
    {
        $excluded = $this->parse('boots -the')->getExcludedTerms();

        self::assertSame(['the'], array_map(static fn(QueryTerm $t) => $t->text, $excluded));
        self::assertSame(['boots', 'the'], $this->parse('boots OR the')->getTerms()[0]->getTexts());
    }

    public function testSearchSettingsSurviveBeingStoredAndReadBack(): void
    {
        $settings = new SearchSettings();
        $settings->partialMatching = PartialMatchMode::Substring;
        $settings->customStopWords = ['shop'];
        $settings->typoMaxDistance = 2;

        $restored = SearchSettings::fromConfig($settings->toConfig());

        self::assertSame(PartialMatchMode::Substring, $restored->partialMatching);
        self::assertSame(['shop'], $restored->customStopWords);
        self::assertSame(2, $restored->typoMaxDistance);
    }

    public function testRejectsSettingsOutsideTheirAllowedRange(): void
    {
        $settings = new SearchSettings();
        $settings->typoMaxDistance = 5;
        $settings->suggestionLimit = 0;

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('typoMaxDistance', $settings->getErrors());
        self::assertArrayHasKey('suggestionLimit', $settings->getErrors());
    }

    /**
     * @dataProvider unusableSettings
     * @param array<string,mixed> $input
     */
    public function testRefusesAnUnusableSettingRatherThanReadingItAsSomethingElse(array $input, string $attribute): void
    {
        $settings = SearchSettings::fromInput($input);

        self::assertFalse($settings->validate(), 'This should not have been accepted: ' . json_encode($input));
        self::assertArrayHasKey($attribute, $settings->getErrors());
    }

    /**
     * @return array<string,array{array<string,mixed>,string}>
     */
    public static function unusableSettings(): array
    {
        return [
            'a word that looks like a boolean' => [['operators' => 'false'], 'operators'],
            'a word that is not a boolean at all' => [['stopWords' => 'maybe'], 'stopWords'],
            'a number as a boolean' => [['synonyms' => 2], 'synonyms'],
            'a word where a number belongs' => [['minPartialLength' => 'abc'], 'minPartialLength'],
            'a fraction where a whole number belongs' => [['typoMinLength' => '12.7'], 'typoMinLength'],
            'a float where a whole number belongs' => [['suggestionLimit' => 12.7], 'suggestionLimit'],
            'a boolean where a number belongs' => [['typoMaxDistance' => true], 'typoMaxDistance'],
            'a matching mode that does not exist' => [['partialMatching' => 'sideways'], 'partialMatching'],
            'a matching mode that is not even text' => [['partialMatching' => 3], 'partialMatching'],
            'stop words that are not words' => [['customStopWords' => [['nested']]], 'customStopWords'],
            'a setting that does not exist' => [['nonsense' => 1], 'operators'],
        ];
    }

    public function testReadsTheValuesAFormActuallyPosts(): void
    {
        $settings = SearchSettings::fromInput([
            'operators' => '1',
            'stopWords' => '',
            'minPartialLength' => '4',
            'partialMatching' => 'substring',
            'customStopWords' => 'shop, Store ,',
        ]);

        self::assertTrue($settings->validate(), implode(' ', $settings->getErrorSummary(true)));
        self::assertTrue($settings->operators);
        self::assertFalse($settings->stopWords);
        self::assertSame(4, $settings->minPartialLength);
        self::assertSame(PartialMatchMode::Substring, $settings->partialMatching);
        self::assertSame(['shop', 'Store'], $settings->customStopWords);
    }

    public function testStoredSettingsFallBackToDefaultsRatherThanFailingToOpenAnIndex(): void
    {
        // Whatever is in the column, an administrator still has to be able to open the page.
        $settings = SearchSettings::fromConfig(['partialMatching' => 'sideways', 'typoMinLength' => 'abc']);

        self::assertTrue($settings->validate());
        self::assertSame(PartialMatchMode::Prefix, $settings->partialMatching);
        self::assertSame(4, $settings->typoMinLength);
    }

    private function parse(string $text): ParsedQuery
    {
        return $this->pipeline->parse(SearchQuery::create('siteSearch', $text), $this->index, $this->provider);
    }
}
