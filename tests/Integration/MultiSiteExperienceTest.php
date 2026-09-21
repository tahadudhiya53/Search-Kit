<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Entry;
use craft\models\Section;
use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\enums\RuleMatchType;
use Tahadudhiya\SearchKit\enums\SynonymType;
use Tahadudhiya\SearchKit\models\RuleAction;
use Tahadudhiya\SearchKit\models\RulePlan;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\models\SearchRule;
use Tahadudhiya\SearchKit\models\Synonym;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * The whole search experience, across more than one site. A word belonging to one site's content
 * must not turn up while searching another.
 */
class MultiSiteExperienceTest extends IntegrationTestCase
{
    private Section $section;

    /** @var int[] */
    private array $siteIds;

    /** @var Entry[] */
    private array $created = [];

    /** @var Synonym[] */
    private array $synonyms = [];

    /** @var SearchRule[] */
    private array $rules = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->section = $this->multiSiteSection();
        $this->siteIds = array_map(
            static fn($settings) => (int)$settings->siteId,
            array_values($this->section->getSiteSettings()),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->rules as $rule) {
            $this->plugin()->getRules()->deleteRule($rule);
        }

        foreach ($this->synonyms as $synonym) {
            $this->plugin()->getSynonyms()->deleteSynonym($synonym);
        }

        $this->rules = [];

        foreach ($this->created as $entry) {
            Craft::$app->getElements()->deleteElement($entry, true);
        }

        $this->synonyms = [];
        $this->created = [];

        parent::tearDown();
    }

    public function testEachSiteHoldsOnlyItsOwnWords(): void
    {
        [$first, $second] = $this->siteIds;
        $index = $this->indexFor($first);
        $other = $this->indexFor($second);

        $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->plugin()->getIndexing()->processPending($index);
        $this->plugin()->getIndexing()->processPending($other);

        $terms = $this->plugin()->getTerms();

        // One entry, worded differently in each site: the words follow the site, not the element.
        self::assertTrue($terms->isSearchable((int)$index->id, $first, 'zqxfirstsiteword'));
        self::assertFalse($terms->isSearchable((int)$index->id, $first, 'zqxsecondsiteword'));
        self::assertTrue($terms->isSearchable((int)$other->id, $second, 'zqxsecondsiteword'));
        self::assertFalse($terms->isSearchable((int)$other->id, $second, 'zqxfirstsiteword'));
    }

    public function testCompletionsDoNotCrossSiteBoundaries(): void
    {
        [$first, $second] = $this->siteIds;
        $index = $this->indexFor($first);
        $other = $this->indexFor($second);

        $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->plugin()->getIndexing()->processPending($index);
        $this->plugin()->getIndexing()->processPending($other);

        self::assertContains('zqxfirstsiteword', $this->autocomplete($index, 'zqxfirstsite'));
        self::assertSame([], $this->autocomplete($other, 'zqxfirstsite'));

        self::assertContains('zqxsecondsiteword', $this->autocomplete($other, 'zqxsecondsite'));
        self::assertSame([], $this->autocomplete($index, 'zqxsecondsite'));
    }

    public function testAnAllSiteIndexCanBeNarrowedToOneSiteForCompletions(): void
    {
        [$first, $second] = $this->siteIds;
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);

        $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->plugin()->getIndexing()->processPending($index);

        self::assertContains('zqxfirstsiteword', $this->autocomplete($index, 'zqxfirstsite', $first));
        self::assertSame([], $this->autocomplete($index, 'zqxfirstsite', $second));

        // Asked about its whole scope, the index knows both.
        self::assertContains('zqxfirstsiteword', $this->autocomplete($index, 'zqxfirstsite'));
        self::assertContains('zqxsecondsiteword', $this->autocomplete($index, 'zqxsecondsite'));
    }

    public function testCompletionsFollowTheSitesASearchNames(): void
    {
        [$first, $second] = $this->siteIds;
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);

        $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->plugin()->getIndexing()->processPending($index);

        // A list of sites is answered from exactly those sites: naming one leaves the other's words
        // out, and naming both offers each site's own wording.
        self::assertContains('zqxfirstsiteword', $this->completions($index, 'zqxfirstsite', [$first]));
        self::assertSame([], $this->completions($index, 'zqxfirstsite', [$second]));

        $both = [$first, $second];

        self::assertContains('zqxfirstsiteword', $this->completions($index, 'zqxfirstsite', $both));
        self::assertContains('zqxsecondsiteword', $this->completions($index, 'zqxsecondsite', $both));
    }

    /**
     * @param int[] $siteIds
     * @return string[]
     */
    private function completions(SearchIndex $index, string $text, array $siteIds): array
    {
        return $this->plugin()->getSearch()->autocomplete(
            SearchQuery::create($index->handle, $text, ['sites' => $siteIds]),
        );
    }

    public function testASearchOfOneSiteNeverMatchesAnotherSitesWording(): void
    {
        [$first] = $this->siteIds;
        $index = $this->indexFor($first);

        $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->plugin()->getIndexing()->processPending($index);

        self::assertSame(1, $this->search($index, 'zqxfirstsiteword')->total);
        self::assertSame(0, $this->search($index, 'zqxsecondsiteword')->total);
    }

    public function testASynonymScopedToOneSiteOnlyAppliesThere(): void
    {
        [$first, $second] = $this->siteIds;
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);

        $this->createTranslated('Zqxmulti Zqxsitesynonym', 'Zqxmulti Zqxelsewhere');
        $this->plugin()->getIndexing()->processPending($index);

        $this->createSynonym($index, ['zqxsitesynonym', 'zqxsitealias'], $first);

        self::assertSame(1, $this->search($index, 'zqxsitealias', $first)->total);
        self::assertSame(0, $this->search($index, 'zqxsitealias', $second)->total);
    }

    public function testACorrectionOnlyReachesWordsTheSearchedSiteHolds(): void
    {
        [$first, $second] = $this->siteIds;
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);

        $this->createTranslated('Zqxmulti Zqxcorrectable', 'Zqxmulti Zqxelsewhere');
        $this->plugin()->getIndexing()->processPending($index);

        $corrected = $this->search($index, 'zqxcorrectabla', $first);

        self::assertTrue($corrected->wasCorrected());
        self::assertStringContainsString('zqxcorrectable', (string)$corrected->correctedText);

        // The other site never holds that word, so nothing there may be corrected to it.
        self::assertFalse($this->search($index, 'zqxcorrectabla', $second)->wasCorrected());
    }

    public function testARuleScopedToOneSiteLeavesTheOtherSitesResultAlone(): void
    {
        [$first, $second] = $this->siteIds;
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $entry = $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->plugin()->getIndexing()->processPending($index);

        $everySite = $this->search($index, 'zqxmulti');
        self::assertSame(2, $everySite->total, 'The same entry should be found in both sites.');

        $this->rule($index, ['siteId' => $first], [$this->hide($entry, RuleActionType::Hide)]);

        $result = $this->search($index, 'zqxmulti');

        // The same element ID, but only the site the rule named was removed.
        self::assertSame(1, $result->total);
        self::assertSame([$second], array_map(static fn($hit) => (int)$hit->siteId, $result->hits));
    }

    public function testARuleWithNoSiteOfItsOwnReachesEverySiteTheSearchCovers(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $entry = $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->plugin()->getIndexing()->processPending($index);

        $this->rule($index, [], [$this->hide($entry, RuleActionType::Hide)]);

        self::assertSame(0, $this->search($index, 'zqxmulti')->total);
    }
    public function testAPlacedResultLandsOnlyInTheSiteItsRuleNames(): void
    {
        [$first, $second] = $this->siteIds;
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $placed = $this->createTranslated('Zqxplaced Zqxfirstsiteword', 'Zqxplaced Zqxsecondsiteword');
        $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->plugin()->getIndexing()->processPending($index);

        $this->rule($index, ['siteId' => $second], [$this->hide($placed, RuleActionType::Pin, 1)]);

        $result = $this->search($index, 'zqxmulti');

        self::assertSame((int)$placed->id, $result->getElementIds()[0]);
        self::assertSame($second, (int)$result->hits[0]->siteId);
        self::assertTrue($result->hits[0]->pinned);
        self::assertNotNull($result->hits[0]->element);
        self::assertSame($second, (int)$result->hits[0]->element->siteId);
    }

    public function testAPlacementOnAnAllSiteIndexNeedsARuleSiteToLandIn(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $entry = $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');

        $rule = new SearchRule([
            'indexId' => $index->id,
            'name' => 'Nowhere to place',
            'matchType' => RuleMatchType::Exact,
            'matchValue' => 'zqxmulti',
        ]);
        $rule->setActions([$this->hide($entry, RuleActionType::Pin, 1)]);

        // Which site's version would be pinned is genuinely ambiguous, so it is refused.
        self::assertFalse($this->plugin()->getRules()->saveRule($rule));
        self::assertNotEmpty($rule->getErrors('actions'));
    }

    public function testASiteSpecificRedirectDoesNotSendASearchCoveringEverySite(): void
    {
        [$first] = $this->siteIds;
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->plugin()->getIndexing()->processPending($index);

        $this->rule($index, ['siteId' => $first], [
            new RuleAction(['type' => RuleActionType::Redirect, 'value' => '/support']),
        ]);

        // A redirect sends the whole search somewhere, so one site's rule cannot claim a search
        // spanning both — but it does claim a search confined to its own site.
        self::assertNull($this->search($index, 'zqxmulti')->redirect);
        self::assertSame('/support', $this->search($index, 'zqxmulti', $first)->redirect);
    }

    public function testAGlobalRuleStillGovernsTheSitesASiteSpecificRuleDidNotTake(): void
    {
        [$first, $second] = $this->siteIds;
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $contested = $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->createTranslated('Zqxmulti Zqxotherone', 'Zqxmulti Zqxothertwo');
        $this->plugin()->getIndexing()->processPending($index);

        // The site rule takes the first site; the global hide should still clear the second.
        $this->rule($index, ['siteId' => $first, 'priority' => 10], [$this->hide($contested, RuleActionType::Pin, 1)]);
        $this->rule($index, ['priority' => 5], [$this->hide($contested, RuleActionType::Hide)]);

        $rows = array_map(
            static fn($hit) => [$hit->elementId, (int)$hit->siteId],
            $this->search($index, 'zqxmulti')->hits,
        );

        self::assertSame([(int)$contested->id, $first], $rows[0], 'the site rule keeps its own site');
        self::assertNotContains([(int)$contested->id, $second], $rows, 'the global hide still clears the rest');
    }

    public function testAResultPinnedInOneSiteIsNotAlsoMovedThereByAGlobalBoost(): void
    {
        [$first, $second] = $this->siteIds;
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $contested = $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->createTranslated('Zqxmulti Zqxotherone', 'Zqxmulti Zqxothertwo');
        $this->plugin()->getIndexing()->processPending($index);

        $this->rule($index, ['siteId' => $first, 'priority' => 10], [$this->hide($contested, RuleActionType::Pin, 1)]);
        $this->rule($index, ['priority' => 5], [new RuleAction([
            'type' => RuleActionType::Boost,
            'elementId' => $contested->id,
            'elementType' => Entry::class,
            'amount' => 900.0,
        ])]);

        $result = $this->search($index, 'zqxmulti');
        $rows = array_map(static fn($hit) => [$hit->elementId, (int)$hit->siteId], $result->hits);

        // The first site's copy is pinned, so it appears once there and is not also merged back in
        // as a boosted result; the second site's copy is boosted as the global rule asked.
        self::assertSame([[(int)$contested->id, $first], [(int)$contested->id, $second]], array_slice($rows, 0, 2));
        self::assertCount(4, $rows, 'nothing may be counted twice');
        self::assertSame(4, $result->total);
    }

    public function testAHigherPriorityGlobalRuleDecidesEverySite(): void
    {
        [$first, $second] = $this->siteIds;
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $contested = $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->createTranslated('Zqxmulti Zqxotherone', 'Zqxmulti Zqxothertwo');
        $this->plugin()->getIndexing()->processPending($index);

        $this->rule($index, ['priority' => 10], [$this->hide($contested, RuleActionType::Hide)]);
        $this->rule($index, ['siteId' => $first, 'priority' => 5], [$this->hide($contested, RuleActionType::Pin, 1)]);

        $rows = array_map(
            static fn($hit) => [$hit->elementId, (int)$hit->siteId],
            $this->search($index, 'zqxmulti')->hits,
        );

        // The global hide got there first, so it decides both sites and the pin is refused.
        self::assertNotContains([(int)$contested->id, $first], $rows);
        self::assertNotContains([(int)$contested->id, $second], $rows);
    }

    public function testASiteSpecificBoostMovesOnlyThatSitesCopyOfTheSameElement(): void
    {
        [$first, $second] = $this->siteIds;
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $lifted = $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->createTranslated('Zqxmulti Zqxotherone', 'Zqxmulti Zqxothertwo');
        $this->plugin()->getIndexing()->processPending($index);

        $this->rule($index, ['siteId' => $second], [
            new RuleAction([
                'type' => RuleActionType::Boost,
                'elementId' => $lifted->id,
                'elementType' => Entry::class,
                'amount' => 900.0,
            ]),
        ]);

        $rows = array_map(
            static fn($hit) => [$hit->elementId, (int)$hit->siteId],
            $this->search($index, 'zqxmulti')->hits,
        );

        // Only the second site's copy is lifted; the same element in the first site is untouched.
        self::assertSame([(int)$lifted->id, $second], $rows[0]);
        self::assertContains([(int)$lifted->id, $first], $rows);
        self::assertNotSame([(int)$lifted->id, $first], $rows[0]);
        self::assertCount(4, $rows, 'the boosted result must not be duplicated');
    }

    public function testTheSameElementPlacedInTwoSitesIsWithheldOnlyWhereItIsUnviewable(): void
    {
        [$first, $second] = $this->siteIds;
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);
        $placed = $this->createTranslated('Zqxplaced Zqxfirstsiteword', 'Zqxplaced Zqxsecondsiteword');
        $this->createTranslated('Zqxmulti Zqxfirstsiteword', 'Zqxmulti Zqxsecondsiteword');
        $this->plugin()->getIndexing()->processPending($index);

        // One element, pinned by a rule in each site.
        $this->rule($index, ['siteId' => $first, 'priority' => 10], [$this->hide($placed, RuleActionType::Pin, 1)]);
        $this->rule($index, ['siteId' => $second, 'priority' => 5], [$this->hide($placed, RuleActionType::Pin, 2)]);

        $both = $this->search($index, 'zqxmulti');
        self::assertSame(
            [[(int)$placed->id, $first], [(int)$placed->id, $second]],
            array_map(static fn($hit) => [$hit->elementId, (int)$hit->siteId], array_slice($both->hits, 0, 2)),
        );

        // Now it stops being viewable in the first site only.
        $inFirst = Entry::find()->id($placed->id)->siteId($first)->status(null)->one();
        self::assertNotNull($inFirst);
        $inFirst->enabledForSite = false;
        self::assertTrue(Craft::$app->getElements()->saveElement($inFirst));

        $result = $this->search($index, 'zqxmulti');
        $placedRows = array_values(array_filter(
            $result->hits,
            static fn($hit) => $hit->elementId === (int)$placed->id,
        ));

        // The second site's copy is untouched; only the first site's is withheld.
        self::assertCount(1, $placedRows);
        self::assertSame($second, (int)$placedRows[0]->siteId);

        $effects = [];
        foreach ($result->rules as $evaluation) {
            foreach ($evaluation->effects as $effect) {
                $effects[] = $effect;
            }
        }

        $named = array_values(array_filter($effects, static fn($e) => ($e['elementId'] ?? null) === (int)$placed->id));
        $withheld = array_values(array_filter($effects, static fn($e) => ($e['outcome'] ?? null) === RulePlan::NOT_VIEWABLE));

        self::assertNotSame([], $named, 'the site that showed the result still names it');
        foreach ($named as $effect) {
            self::assertSame($second, $effect['siteId'], 'only the shown site may be named');
        }

        self::assertNotSame([], $withheld, 'the withheld site is reported as not viewable');
        foreach ($withheld as $effect) {
            self::assertNull($effect['elementId']);
            self::assertNull($effect['siteId']);
            self::assertFalse($effect['applied']);
        }
    }

    private function hide(Entry $entry, RuleActionType $type, ?int $position = null): RuleAction
    {
        return new RuleAction([
            'type' => $type,
            'elementId' => $entry->id,
            'elementType' => Entry::class,
            'position' => $position,
        ]);
    }

    /**
     * @param array<string,mixed> $config
     * @param RuleAction[] $actions
     */
    private function rule(SearchIndex $index, array $config, array $actions): SearchRule
    {
        $rule = new SearchRule($config + [
            'indexId' => $index->id,
            'name' => 'Multi-site rule',
            'matchType' => RuleMatchType::Exact,
            'matchValue' => 'zqxmulti',
        ]);
        $rule->setActions($actions);

        self::assertTrue($this->plugin()->getRules()->saveRule($rule), implode(' ', $rule->getErrorSummary(true)));
        $this->rules[] = $rule;

        return $rule;
    }

    /**
     * @param array<string> $terms
     */
    private function createSynonym(SearchIndex $index, array $terms, ?int $siteId): Synonym
    {
        $synonym = new Synonym([
            'indexId' => $index->id,
            'siteId' => $siteId,
            'type' => SynonymType::TwoWay,
            'terms' => $terms,
        ]);

        self::assertTrue($this->plugin()->getSynonyms()->saveSynonym($synonym), implode(' ', $synonym->getErrorSummary(true)));
        $this->synonyms[] = $synonym;

        return $synonym;
    }

    private function indexFor(int $siteId): SearchIndex
    {
        return $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class, $siteId);
    }

    /**
     * @return string[]
     */
    private function autocomplete(SearchIndex $index, string $text, ?int $siteId = null): array
    {
        $params = $siteId !== null ? ['site' => $siteId] : [];

        return $this->plugin()->getSearch()->autocomplete(
            SearchQuery::create($index->handle, $text, $params),
        );
    }

    private function search(SearchIndex $index, string $text, ?int $siteId = null): SearchResult
    {
        $params = $siteId !== null ? ['site' => $siteId] : [];

        return $this->plugin()->getSearch()->search(SearchQuery::create($index->handle, $text, $params));
    }

    /**
     * One entry, worded differently in each site. Titles translate per site here, which is the only
     * way two sites can genuinely hold different words for the same content.
     */
    private function createTranslated(string $first, string $second): Entry
    {
        [$firstSite, $secondSite] = $this->siteIds;
        $entry = $this->createInSite($first, $firstSite);

        $translated = Entry::find()->id($entry->id)->siteId($secondSite)->status(null)->one();

        if ($translated === null) {
            self::markTestSkipped('The section under test did not propagate to its second site.');
        }

        $translated->title = $second;

        if (!Craft::$app->getElements()->saveElement($translated)) {
            self::fail('Could not translate test content: ' . implode(' ', $translated->getErrorSummary(true)));
        }

        return $entry;
    }

    private function createInSite(string $title, int $siteId): Entry
    {
        $entry = new Entry();
        $entry->sectionId = $this->section->id;
        $entry->typeId = $this->section->getEntryTypes()[0]->id;
        $entry->siteId = $siteId;
        $entry->title = $title;
        $entry->enabled = true;

        if (!Craft::$app->getElements()->saveElement($entry)) {
            self::fail('Could not create test content: ' . implode(' ', $entry->getErrorSummary(true)));
        }

        $this->created[] = $entry;

        return $entry;
    }

    private function multiSiteSection(): Section
    {
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($section->type !== Section::TYPE_SINGLE && count($section->getSiteSettings()) > 1) {
                return $section;
            }
        }

        self::markTestSkipped('This project has no section covering more than one site.');
    }
}
