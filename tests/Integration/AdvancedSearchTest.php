<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\enums\RuleMatchType;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\models\Facet;
use Tahadudhiya\SearchKit\models\RuleAction;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\models\SearchRule;
use Tahadudhiya\SearchKit\models\Synonym;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\services\Normalization;
use Tahadudhiya\SearchKit\Tests\Support\FixedLanguages;
use Tahadudhiya\SearchKit\Tests\Support\MinimalProvider;

/**
 * Covers counting a result set by a field, filtering by a range, searching a subset of an index's
 * sites, and reading query text in the language of the site it is searching.
 */
class AdvancedSearchTest extends SearchContentTestCase
{
    private const TERM = 'zqxwfacet';

    private SearchIndex $index;

    /** @var array<string,int> Element IDs keyed by the distinctive part of their title. */
    private array $ids = [];

    /** @var Synonym[] Groups to remove when the test finishes. */
    private array $createdSynonyms = [];

    /** @var SearchRule[] Rules to remove when the test finishes. */
    private array $createdRules = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title', Category::class => 'title'],
            CraftProvider::class,
            $this->fieldSectionSiteId(),
        );

        $this->ids = [
            'alpha' => (int)$this->createPage('Zqxwfacet Alpha')->id,
            'bravo' => (int)$this->createPage('Zqxwfacet Bravo')->id,
            'charlie' => (int)$this->createPage('Zqxwfacet Charlie')->id,
            'delta' => (int)$this->createCategory('Zqxwfacet Delta')->id,
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdSynonyms as $synonym) {
            $this->plugin()->getSynonyms()->deleteSynonym($synonym);
        }

        foreach ($this->createdRules as $rule) {
            $this->plugin()->getRules()->deleteRule($rule);
        }

        $this->createdSynonyms = [];
        $this->createdRules = [];
        $this->readEveryLanguageAsItIs();

        parent::tearDown();
    }

    public function testResultsAreCountedByTheKindOfElementTheyAre(): void
    {
        $facet = $this->facet($this->search(['facets' => 'elementType']), 'elementType');

        self::assertSame(['entry' => 3, 'category' => 1], $facet->getCounts());
    }

    public function testResultsAreCountedByAColumnTheirOwnElementTypeHolds(): void
    {
        $result = $this->search(['facets' => ['sectionId', 'groupId']]);

        // Only entries carry a section and only categories carry a group, so each facet counts the
        // element type that can answer it and leaves the other alone.
        self::assertSame(
            [(string)$this->fieldSection()->id => 3],
            $this->facet($result, 'sectionId')->getCounts(),
        );
        self::assertSame(
            [(string)$this->fieldCategoryGroup()->id => 1],
            $this->facet($result, 'groupId')->getCounts(),
        );
    }

    public function testCountsDescribeEveryResultRatherThanThePage(): void
    {
        $result = $this->search(['facets' => 'elementType', 'limit' => 1]);

        self::assertCount(1, $result->hits);
        self::assertSame(4, array_sum($this->facet($result, 'elementType')->getCounts()));
    }

    public function testCountsAreNarrowedByTheSameFiltersTheSearchWas(): void
    {
        $result = $this->search([
            'facets' => 'elementType',
            'filters' => ['elementType' => 'entry'],
        ]);

        self::assertSame(['entry' => 3], $this->facet($result, 'elementType')->getCounts());
    }

    public function testARuleHidingAResultTakesItOutOfTheCountsToo(): void
    {
        $this->rule($this->index, ['matchValue' => self::TERM], [
            new RuleAction([
                'type' => RuleActionType::Hide,
                'elementId' => $this->ids['delta'],
                'elementType' => Category::class,
            ]),
        ]);

        $result = $this->search(['facets' => 'elementType']);

        self::assertSame(3, $result->total);
        self::assertSame(['entry' => 3], $this->facet($result, 'elementType')->getCounts());
    }

    public function testARulePlacingAResultLeavesItCounted(): void
    {
        $this->rule($this->index, ['matchValue' => self::TERM], [
            new RuleAction([
                'type' => RuleActionType::Pin,
                'position' => 1,
                'elementId' => $this->ids['delta'],
                'elementType' => Category::class,
            ]),
            new RuleAction([
                'type' => RuleActionType::Promote,
                'elementId' => $this->ids['charlie'],
                'elementType' => Entry::class,
            ]),
        ]);

        $result = $this->search(['facets' => 'elementType']);

        // A placed result is still a result, so it is counted where the provider found it.
        self::assertSame(4, $result->total);
        self::assertSame(['entry' => 3, 'category' => 1], $this->facet($result, 'elementType')->getCounts());
        self::assertSame($this->ids['delta'], $result->hits[0]->elementId);
    }

    public function testAFieldNothingCanBeCountedByIsRefused(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->search(['facets' => 'nonsenseColumn']);
    }

    public function testAProviderThatCannotCountIsRefusedRatherThanAnsweredWithNothing(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title'], MinimalProvider::class);

        self::assertFalse((new MinimalProvider())->supports(ProviderCapability::Faceting));

        $this->expectException(UnsupportedCapabilityException::class);
        $this->searchIndex($index->handle, self::TERM, ['facets' => 'elementType']);
    }

    public function testARangeFilterKeepsOnlyWhatFallsInsideIt(): void
    {
        $sorted = [$this->ids['alpha'], $this->ids['bravo'], $this->ids['charlie']];
        sort($sorted);

        $result = $this->search([
            'filters' => ['id' => ['between' => [$sorted[0], $sorted[1]]]],
            'orderBy' => 'id asc',
        ]);

        self::assertSame([$sorted[0], $sorted[1]], $this->idsOf($result));
    }

    public function testARangeFilterNeedsABottomAndATop(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->search(['filters' => ['id' => ['between' => [$this->ids['alpha']]]]]);
    }

    public function testASearchCanNameTheSitesItCovers(): void
    {
        $siteIds = $this->fieldSectionSiteIds();

        if (count($siteIds) < 2) {
            self::markTestSkipped('This project needs a section enabled for at least two sites.');
        }

        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $entry = $this->createPage('Zqxwfacet Everywhere');

        foreach ($siteIds as $siteId) {
            $variant = Entry::find()->id($entry->id)->siteId($siteId)->status(null)->one();

            if ($variant !== null) {
                Craft::$app->getSearch()->indexElementAttributes($variant);
            }
        }

        $text = 'zqxwfacet everywhere';
        $listed = $this->searchIndex($index->handle, $text, ['sites' => $siteIds]);

        self::assertSame($this->searchIndex($index->handle, $text)->total, $listed->total);
        self::assertEqualsCanonicalizing($siteIds, array_column($listed->hits, 'siteId'));

        // The same list with one site left out is a narrower search, not a differently ordered one.
        $narrowed = $this->searchIndex($index->handle, $text, ['sites' => [$siteIds[1]]]);

        self::assertSame(1, $narrowed->total);
        self::assertSame($siteIds[1], $narrowed->hits[0]->siteId);
    }

    public function testASiteListCannotWidenAnIndexesScope(): void
    {
        $siteIds = $this->fieldSectionSiteIds();
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class, $siteIds[0]);

        $this->expectException(InvalidQueryException::class);
        $this->searchIndex($index->handle, self::TERM, ['sites' => $siteIds]);
    }

    public function testASiteListOfOneIsTheSameSearchAsNamingThatSite(): void
    {
        $siteId = $this->fieldSectionSiteId();
        $query = SearchQuery::create($this->index->handle, self::TERM, ['sites' => [$siteId]]);

        self::assertSame($siteId, $query->siteId);
        self::assertSame([$siteId], $query->getSiteScope());
        self::assertSame($siteId, $query->getSiteScopeId());
    }

    public function testEverySiteBeingSearchedReadsTheQueryInItsOwnLanguage(): void
    {
        $siteIds = $this->fieldSectionSiteIds();

        if (count($siteIds) < 2) {
            self::markTestSkipped('This project needs a section enabled for at least two sites.');
        }

        // This project writes every site in English, so the second site's language is stood in for
        // here. Everything else — the pipeline, the provider, the content and its keywords — is real.
        $this->readSiteAs($siteIds[1], 'de-DE');

        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $this->createPage('Zqxwfacet Grüße');

        foreach ($siteIds as $siteId) {
            $variant = Entry::find()->id($this->ids['alpha'])->siteId($siteId)->status(null)->one();

            if ($variant !== null) {
                Craft::$app->getSearch()->indexElementAttributes($variant);
            }
        }

        $both = $this->searchIndex($index->handle, 'Grüße', ['sites' => $siteIds]);

        $english = (string)Craft::$app->getSites()->getSiteById($siteIds[0])?->language;

        self::assertSame([$english, 'de-DE'], $both->parsedQuery?->languages);

        // Content was indexed in English, so the English reading is what finds it — and it is only
        // searched for because the search covers an English site.
        self::assertNotSame([], $both->hits, 'Both readings of the word should have been searched for.');
        self::assertSame(['grusse', 'gruesse'], $this->terms($both));

        // The German site alone is read in German alone, and English keywords are not German ones.
        $german = $this->searchIndex($index->handle, 'Grüße', ['sites' => [$siteIds[1]]]);

        self::assertSame(['gruesse'], $this->terms($german));
        self::assertSame([], $german->hits);
    }

    public function testARuleForOneSiteOnlyReachesThatSitesCopyOfAResult(): void
    {
        $siteIds = $this->fieldSectionSiteIds();

        if (count($siteIds) < 2) {
            self::markTestSkipped('This project needs a section enabled for at least two sites.');
        }

        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $entry = $this->createPage('Zqxwscope Ruled');

        foreach ($siteIds as $siteId) {
            $variant = Entry::find()->id($entry->id)->siteId($siteId)->status(null)->one();

            if ($variant !== null) {
                Craft::$app->getSearch()->indexElementAttributes($variant);
            }
        }

        $this->rule($index, ['siteId' => $siteIds[0]], [
            new RuleAction([
                'type' => RuleActionType::Hide,
                'elementId' => (int)$entry->id,
                'elementType' => Entry::class,
            ]),
            new RuleAction(['type' => RuleActionType::Redirect, 'value' => '/support']),
        ]);

        // The rule's own site is hidden and sent on; the other site's copy is untouched, and a
        // search covering both is not redirected by a rule written for one of them.
        $hidden = $this->searchIndex($index->handle, 'zqxwscope ruled', ['sites' => [$siteIds[0]]]);

        self::assertSame([], $hidden->hits);
        self::assertSame('/support', $hidden->redirect);

        $other = $this->searchIndex($index->handle, 'zqxwscope ruled', ['sites' => [$siteIds[1]]]);

        self::assertSame([(int)$entry->id], $other->getElementIds());
        self::assertNull($other->redirect);

        $both = $this->searchIndex($index->handle, 'zqxwscope ruled', ['sites' => $siteIds]);

        self::assertSame([$siteIds[1]], array_column($both->hits, 'siteId'));
        self::assertNull($both->redirect, 'A rule for one site must not redirect a search of several.');
    }

    public function testCountsFollowTheSitesAndFiltersTheSearchWasNarrowedBy(): void
    {
        $siteIds = $this->fieldSectionSiteIds();

        if (count($siteIds) < 2) {
            self::markTestSkipped('This project needs a section enabled for at least two sites.');
        }

        $index = $this->persistIndexWithFields(
            [Entry::class => 'title', Category::class => 'title'],
            CraftProvider::class,
        );

        foreach ([$this->ids['alpha'], $this->ids['bravo']] as $id) {
            foreach ($siteIds as $siteId) {
                $variant = Entry::find()->id($id)->siteId($siteId)->status(null)->one();

                if ($variant !== null) {
                    Craft::$app->getSearch()->indexElementAttributes($variant);
                }
            }
        }

        $listed = $this->searchIndex($index->handle, self::TERM, [
            'sites' => $siteIds,
            'facets' => ['siteId', 'elementType'],
        ]);

        // Every site named is counted, and nothing outside the scope is.
        self::assertEqualsCanonicalizing(
            array_map('strval', $siteIds),
            array_column($listed->getFacet('siteId')?->getValues() ?? [], 'value'),
        );

        $narrowed = $this->searchIndex($index->handle, self::TERM, [
            'sites' => [$siteIds[0]],
            'facets' => ['siteId', 'elementType'],
        ]);

        self::assertSame(
            [(string)$siteIds[0]],
            array_column($narrowed->getFacet('siteId')?->getValues() ?? [], 'value'),
        );

        // A filter narrows the counts exactly as it narrows the results.
        $filtered = $this->searchIndex($index->handle, self::TERM, [
            'sites' => $siteIds,
            'facets' => ['siteId', 'elementType'],
            'filters' => ['elementType' => 'entry'],
        ]);

        self::assertSame(
            ['entry'],
            array_column($filtered->getFacet('elementType')?->getValues() ?? [], 'value'),
        );
        self::assertSame($filtered->total, array_sum($filtered->getFacet('siteId')?->getCounts() ?? []));
    }

    /**
     * @param array<string,mixed> $config
     * @param RuleAction[] $actions
     */
    private function rule(SearchIndex $index, array $config, array $actions): SearchRule
    {
        $rule = new SearchRule($config + [
            'indexId' => $index->id,
            'name' => 'Advanced search test rule',
            'matchType' => RuleMatchType::Exact,
            'matchValue' => 'zqxwscope ruled',
        ]);

        $rule->setActions($actions);

        self::assertTrue($this->plugin()->getRules()->saveRule($rule), implode(' ', $rule->getErrorSummary(true)));
        $this->createdRules[] = $rule;

        return $rule;
    }

    public function testASearchWhoseSitesReadTheQueryDifferentlyIsRefused(): void
    {
        $siteIds = $this->fieldSectionSiteIds();

        if (count($siteIds) < 2) {
            self::markTestSkipped('This project needs a section enabled for at least two sites.');
        }

        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);

        // Craft reads the Cyrillic hard sign as a word in English and as nothing in Russian, so
        // these two sites do not read “zqxwfacet ъ” as the same words at all.
        $this->readSiteAs($siteIds[1], 'ru');

        try {
            $this->searchIndex($index->handle, 'zqxwfacet ъ', ['sites' => $siteIds]);
            self::fail('A query the searched sites read differently should be refused.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('text', $e->getErrors());
            self::assertStringContainsString('stand in for', $e->getErrors()['text'][0]);
        }

        // Each site on its own reads it for itself, and is answered rather than refused.
        $english = $this->searchIndex($index->handle, 'zqxwfacet ъ', ['sites' => [$siteIds[0]]]);

        self::assertSame(['en'], $english->parsedQuery?->languages);
        self::assertSame(['zqxwfacet', 'ie'], $this->termTexts($english));

        $russian = $this->searchIndex($index->handle, 'zqxwfacet ъ', ['sites' => [$siteIds[1]]]);

        self::assertSame(['ru'], $russian->parsedQuery?->languages);
        self::assertSame(['zqxwfacet'], $this->termTexts($russian));

        // A word every language reads the same way still covers both sites at once.
        $shared = $this->searchIndex($index->handle, self::TERM, ['sites' => $siteIds]);

        self::assertSame(['en', 'ru'], $shared->parsedQuery?->languages);
        self::assertNotSame([], $shared->hits);
    }

    public function testASiteScopedSynonymIsNotAppliedToASearchCoveringAnotherSite(): void
    {
        $siteIds = $this->fieldSectionSiteIds();

        if (count($siteIds) < 2) {
            self::markTestSkipped('This project needs a section enabled for at least two sites.');
        }

        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $entry = $this->createPage('Zqxwfacet Wellies');

        foreach ($siteIds as $siteId) {
            $variant = Entry::find()->id($entry->id)->siteId($siteId)->status(null)->one();

            if ($variant !== null) {
                Craft::$app->getSearch()->indexElementAttributes($variant);
            }
        }

        $this->saveSynonym(['zqxwboots', 'wellies'], $siteIds[0]);

        // The site the group was written for finds what it stands for.
        self::assertSame(
            [(int)$entry->id],
            $this->searchIndex($index->handle, 'zqxwfacet zqxwboots', ['sites' => [$siteIds[0]]])->getElementIds(),
        );

        // A search covering the other site does not, because applying it there would return a
        // result a search of that site alone never had.
        $both = $this->searchIndex($index->handle, 'zqxwfacet zqxwboots', ['sites' => $siteIds]);

        self::assertSame([], $both->hits);
        self::assertSame(['wellies'], $both->parsedQuery?->withheldSynonyms);

        // A group covering every site is a group the whole search may use.
        $this->saveSynonym(['zqxwboots', 'wellies'], null);

        $global = $this->searchIndex($index->handle, 'zqxwfacet zqxwboots', ['sites' => $siteIds]);

        self::assertSame(count($siteIds), $global->total);
        self::assertSame([], $global->parsedQuery?->withheldSynonyms);
    }

    /**
     * @param string[] $terms
     */
    private function saveSynonym(array $terms, ?int $siteId): void
    {
        $synonym = new Synonym([
            'indexId' => null,
            'siteId' => $siteId,
            'terms' => $terms,
        ]);

        self::assertTrue($this->plugin()->getSynonyms()->saveSynonym($synonym));
        $this->createdSynonyms[] = $synonym;
    }

    /**
     * Has the services that read text treat one site as written in another language.
     */
    private function readSiteAs(int $siteId, string $language): void
    {
        $normalization = new FixedLanguages(['languages' => [$siteId => $language]]);

        $this->plugin()->getQueryPipeline()->setNormalization($normalization);
        $this->plugin()->getStopWords()->setNormalization($normalization);
        $this->plugin()->getSuggestions()->setNormalization($normalization);
    }

    private function readEveryLanguageAsItIs(): void
    {
        $normalization = new Normalization();

        $this->plugin()->getQueryPipeline()->setNormalization($normalization);
        $this->plugin()->getStopWords()->setNormalization($normalization);
        $this->plugin()->getSuggestions()->setNormalization($normalization);
    }

    public function testQueryTextIsReadInTheLanguageOfTheSiteBeingSearched(): void
    {
        $normalization = $this->plugin()->getNormalization();

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            self::assertSame($site->language, $normalization->siteLanguage((int)$site->id));
        }

        // A search covering more than one site has no one language to read, so it uses the
        // application's, which is what Craft falls back to itself.
        self::assertSame(Craft::$app->language, $normalization->siteLanguage(null));
    }

    /**
     * The text of every term a search ran with.
     *
     * @return string[]
     */
    private function termTexts(SearchResult $result): array
    {
        $parsed = $result->parsedQuery;

        self::assertNotNull($parsed);

        return array_map(static fn($term) => $term->text, $parsed->getTerms());
    }

    /**
     * Every reading of a result's first query term.
     *
     * @return string[]
     */
    private function terms(SearchResult $result): array
    {
        $parsed = $result->parsedQuery;

        self::assertNotNull($parsed);

        return $parsed->getTerms()[0]->getTexts();
    }

    private function facet(SearchResult $result, string $field): Facet
    {
        $facet = $result->getFacet($field);

        self::assertNotNull($facet, "The search was not counted by “{$field}”.");

        return $facet;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function search(array $params = []): SearchResult
    {
        return $this->searchIndex($this->index->handle, self::TERM, $params);
    }
}
