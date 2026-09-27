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
use Tahadudhiya\SearchKit\Tests\Support\FixedLanguages;
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

    // --------------------------------------------------------------- more than one language

    public function testEachLanguageBeingSearchedReadsTheTextForItself(): void
    {
        $parsed = $this->multiLingual()->parse(
            SearchQuery::create('siteSearch', 'grüße', ['sites' => [1, 3]]),
            $this->index,
            $this->provider,
        );

        self::assertSame(['en-US', 'de-DE'], $parsed->languages);
        self::assertTrue($parsed->isMultiLingual());

        // German folds the word one way and English another, and each site's content was indexed
        // in its own — so both readings are accepted rather than one standing in for the other.
        self::assertSame('grusse', $parsed->getTerms()[0]->text);
        self::assertSame(['grusse', 'gruesse'], $parsed->getTerms()[0]->getTexts());
    }

    public function testALanguagesAgreeingOnAWordLeaveItAsOneTerm(): void
    {
        $parsed = $this->multiLingual()->parse(
            SearchQuery::create('siteSearch', 'boots', ['sites' => [1, 3]]),
            $this->index,
            $this->provider,
        );

        self::assertSame(['boots'], $parsed->getTerms()[0]->getTexts());
        self::assertFalse($parsed->hasAlternatives());
    }

    public function testAProviderThatCannotAcceptBothReadingsIsRefused(): void
    {
        $provider = new StubProvider();
        $provider->supported = array_values(array_filter(
            CraftProvider::capabilities(),
            static fn(ProviderCapability $capability) => $capability !== ProviderCapability::TermAlternation,
        ));

        // Running one reading and calling it the other would search a site for a word it does not
        // hold, so the search is refused instead.
        $this->expectException(UnsupportedCapabilityException::class);
        $this->multiLingual()->parse(
            SearchQuery::create('siteSearch', 'grüße', ['sites' => [1, 3]]),
            $this->index,
            $provider,
        );
    }

    public function testTheBuiltInStopWordsAreNotAppliedToASearchThatIsNotAllEnglish(): void
    {
        $settings = $this->index->getSearchSettings();
        $settings->customStopWords = ['bitte'];

        $mixed = $this->multiLingual()->parse(
            SearchQuery::create('siteSearch', 'the was bitte boots', ['sites' => [1, 3]]),
            $this->index,
            $this->provider,
        );

        // “Was” narrows a German query, so the English list is not applied to a search covering both.
        self::assertContains('was', array_map(static fn(QueryTerm $t) => $t->text, $mixed->getTerms()));
        self::assertContains('the', array_map(static fn(QueryTerm $t) => $t->text, $mixed->getTerms()));

        // A word somebody configured is theirs, whatever language the search is read in.
        self::assertSame(['bitte'], $mixed->removedStopWords);

        $english = $this->multiLingual()->parse(
            SearchQuery::create('siteSearch', 'the was bitte boots', ['sites' => [1]]),
            $this->index,
            $this->provider,
        );

        self::assertEqualsCanonicalizing(['bitte', 'the', 'was'], $english->removedStopWords);
    }

    public function testOnlyTheSynonymsHoldingInEverySiteBeingSearchedAreApplied(): void
    {
        $this->synonyms->sites = [1, 3];
        $this->synonyms->expansionsBySite = [
            1 => ['boots' => ['footwear', 'wellies'], 'grusse' => ['hallo']],
            3 => ['boots' => ['footwear'], 'gruesse' => ['hallo']],
        ];

        $pipeline = $this->multiLingual();

        $parsed = $pipeline->parse(
            SearchQuery::create('siteSearch', 'boots', ['sites' => [1, 3]]),
            $this->index,
            $this->provider,
        );

        // “Wellies” was written for one site alone, so applying it would have returned results in
        // the other that a search of it alone never had.
        self::assertSame(['boots', 'footwear'], $parsed->getTerms()[0]->getTexts());
        self::assertSame(['wellies'], $parsed->withheldSynonyms);

        // Each site is asked with its own reading of the word, so a group written in either is found.
        $greeting = $pipeline->parse(
            SearchQuery::create('siteSearch', 'grüße', ['sites' => [1, 3]]),
            $this->index,
            $this->provider,
        );

        self::assertContains('hallo', $greeting->getTerms()[0]->getTexts());
    }

    public function testASearchOfOneSiteStillGetsThatSitesOwnSynonyms(): void
    {
        $this->synonyms->sites = [1, 3];
        $this->synonyms->expansionsBySite = [
            1 => ['boots' => ['wellies']],
            3 => ['boots' => ['stiefel']],
        ];

        foreach ([[1, 'wellies'], [3, 'stiefel']] as [$siteId, $expected]) {
            $parsed = $this->multiLingual()->parse(
                SearchQuery::create('siteSearch', 'boots', ['sites' => [$siteId]]),
                $this->index,
                $this->provider,
            );

            self::assertSame(['boots', $expected], $parsed->getTerms()[0]->getTexts());
            self::assertSame([], $parsed->withheldSynonyms);
        }
    }

    public function testASearchWhoseLanguagesReadTheTextDifferentlyIsRefused(): void
    {
        // Craft reads the Cyrillic hard sign as a word in English and as nothing in Russian, so
        // “boots ъ” is two words to one site and one word to the other.
        $this->index->getSearchSettings()->operators = false;

        try {
            $this->multiLingual(3, 'ru')->parse(
                SearchQuery::create('siteSearch', 'boots ъ', ['sites' => [1, 3]]),
                $this->index,
                $this->provider,
            );
            self::fail('A query the languages do not read as the same words should be refused.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('text', $e->getErrors());
            self::assertStringContainsString('en-US', $e->getErrors()['text'][0]);
            self::assertStringContainsString('ru', $e->getErrors()['text'][0]);
        }
    }

    public function testAWordOneLanguageReadsAsNothingIsRefusedRatherThanReadAsTheOthers(): void
    {
        // The same disagreement with operators on, where each word is read on its own: running the
        // English reading would search the Russian site for a word nobody typed.
        $this->expectException(InvalidQueryException::class);
        $this->multiLingual(3, 'ru')->parse(
            SearchQuery::create('siteSearch', 'boots ъ', ['sites' => [1, 3]]),
            $this->index,
            $this->provider,
        );
    }

    public function testLanguagesThatReadTheTextAsTheSameWordsAreStillRun(): void
    {
        $this->index->getSearchSettings()->operators = false;

        $parsed = $this->multiLingual()->parse(
            SearchQuery::create('siteSearch', 'grüße boots', ['sites' => [1, 3]]),
            $this->index,
            $this->provider,
        );

        // Both languages make two words of it, so each word keeps every reading of itself.
        self::assertSame(['grusse', 'gruesse'], $parsed->getTerms()[0]->getTexts());
        self::assertSame(['boots'], $parsed->getTerms()[1]->getTexts());
    }

    public function testASearchOfSitesSharingALanguageIsReadOnceAndNeverRefused(): void
    {
        foreach ([true, false] as $operators) {
            $this->index->getSearchSettings()->operators = $operators;

            // Both sites are written in the same language, so there is nothing to disagree about —
            // not even over a word one other language would read differently.
            $parsed = $this->multiLingual(3, 'en-US')->parse(
                SearchQuery::create('siteSearch', 'boots ъ', ['sites' => [1, 3]]),
                $this->index,
                $this->provider,
            );

            self::assertSame(['en-US'], $parsed->languages);
            self::assertSame(['boots', 'ie'], array_map(static fn(QueryTerm $t) => $t->text, $parsed->getTerms()));
        }
    }

    public function testASearchOfOneSiteIsNeverRefusedOverAnotherSitesLanguage(): void
    {
        foreach ([true, false] as $operators) {
            $this->index->getSearchSettings()->operators = $operators;

            $parsed = $this->multiLingual(3, 'ru')->parse(
                SearchQuery::create('siteSearch', 'boots ъ', ['sites' => [3]]),
                $this->index,
                $this->provider,
            );

            self::assertSame(['ru'], $parsed->languages);
            self::assertSame(['boots'], array_map(static fn(QueryTerm $t) => $t->text, $parsed->getTerms()));
        }
    }

    /**
     * A pipeline whose sites are written in different languages, which this project's are not.
     */
    private function multiLingual(int $siteId = 3, string $language = 'de-DE'): QueryPipeline
    {
        $normalization = new FixedLanguages(['languages' => [1 => 'en-US', $siteId => $language]]);

        $stopWords = new StopWords();
        $stopWords->setNormalization($normalization);

        $pipeline = new QueryPipeline();
        $pipeline->setNormalization($normalization);
        $pipeline->setStopWords($stopWords);
        $pipeline->setSynonyms($this->synonyms);

        return $pipeline;
    }

    public function testTheBuiltInStopWordsOnlyApplyToAnEnglishQuery(): void
    {
        $stopWords = new StopWords();
        $stopWords->setNormalization(new Normalization(['language' => 'en-US']));

        $settings = new SearchSettings();
        $settings->customStopWords = ['bitte'];

        self::assertTrue($stopWords->isStopWord('the', $settings, 'en-GB'));

        // “Die” and “was” narrow a German query down; the built-in list is English and says nothing
        // about that, so it is not applied.
        self::assertFalse($stopWords->isStopWord('the', $settings, 'de-DE'));
        self::assertFalse($stopWords->isStopWord('was', $settings, 'de-DE'));

        // A word somebody configured is theirs, whatever language the search is read in.
        self::assertTrue($stopWords->isStopWord('bitte', $settings, 'de-DE'));
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
