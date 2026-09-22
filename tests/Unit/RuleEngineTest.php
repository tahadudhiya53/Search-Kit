<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use craft\base\ElementInterface;
use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\enums\RuleMatchType;
use Tahadudhiya\SearchKit\models\RuleAction;
use Tahadudhiya\SearchKit\models\RuleEvaluation;
use Tahadudhiya\SearchKit\models\RulePlan;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\models\SearchRule;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\services\Normalization;
use Tahadudhiya\SearchKit\services\RuleEngine;
use Tahadudhiya\SearchKit\Tests\Support\StubRules;

/**
 * The rule engine decides the whole of what merchandising does. These run it against a stand-in for
 * a provider that honours exclusions, so what they assert about ordering, totals and site scope is
 * what a real search produces.
 */
class RuleEngineTest extends TestCase
{
    private const ENTRY = 'craft\elements\Entry';

    private StubRules $rules;
    private RuleEngine $engine;
    private SearchIndex $index;
    private SearchIndex $everySite;
    private ?RulePlan $lastPlan = null;

    protected function setUp(): void
    {
        $normalization = new Normalization();
        $normalization->language = 'en-US';

        $this->rules = new StubRules();
        $this->rules->setNormalization($normalization);

        $this->engine = new RuleEngine();
        $this->engine->setRules($this->rules);
        $this->engine->setNormalization($normalization);

        $this->index = $this->newIndex(1);
        $this->everySite = $this->newIndex(null);
    }

    // ---------------------------------------------------------------- matching

    public function testEveryMatchTypeIsReadAgainstNormalizedText(): void
    {
        $cases = [
            [RuleMatchType::Exact, 'iphone', 'iPhone', true],
            [RuleMatchType::Exact, 'iphone', 'iphone 17', false],
            [RuleMatchType::Contains, 'phone', 'buy an iPhone today', true],
            [RuleMatchType::StartsWith, 'iphone', 'iPhone 17 Pro', true],
            [RuleMatchType::StartsWith, 'iphone', 'the iPhone', false],
            [RuleMatchType::EndsWith, 'pro', 'iPhone 17 Pro', true],
            [RuleMatchType::Wildcard, 'iphone*pro', 'iPhone 17 Pro', true],
            [RuleMatchType::Wildcard, 'iphone*pro', 'iPhone 17 Max', false],
        ];

        foreach ($cases as [$type, $value, $text, $expected]) {
            $this->rules->rules = [];
            $this->rule(['matchType' => $type, 'matchValue' => $value]);

            self::assertSame(
                $expected,
                $this->engine->plan(SearchQuery::create('siteSearch', $text), $this->index)->getMatchedRules() !== [],
                "“{$value}” ({$type->value}) against “{$text}”",
            );
        }
    }

    public function testAPatternIsNeverReadAsARegularExpression(): void
    {
        $this->rule(['matchType' => RuleMatchType::Wildcard, 'matchValue' => 'sup.*t']);

        $plan = $this->engine->plan(SearchQuery::create('siteSearch', 'support'), $this->index);

        self::assertSame([], $plan->getMatchedRules());
    }

    public function testRulesAreMatchedAgainstTheTextTheSearchActuallyRan(): void
    {
        $this->rule(['matchValue' => 'iphone'], [$this->hide(5)]);

        $typed = $this->engine->plan(SearchQuery::create('siteSearch', 'iphno'), $this->index);
        $corrected = $this->engine->plan(SearchQuery::create('siteSearch', 'iphno'), $this->index, 'iphone');

        self::assertSame([], $typed->hidden);
        self::assertCount(1, $corrected->hidden);
    }

    // ---------------------------------------------------------------- eligibility

    public function testADisabledRuleIsRecordedRatherThanApplied(): void
    {
        $this->rule(['enabled' => false], [$this->hide(5)]);

        $plan = $this->engine->plan(SearchQuery::create('siteSearch', 'shoes'), $this->index);

        self::assertSame([], $plan->hidden);
        self::assertSame(RuleEvaluation::DISABLED, $plan->evaluations[0]->reason);
    }

    public function testARuleOutsideItsScheduleDoesNotApply(): void
    {
        $this->rule(['dateStart' => $this->utc('+1 day')], [$this->hide(5)]);

        $plan = $this->engine->plan(SearchQuery::create('siteSearch', 'shoes'), $this->index);

        self::assertSame([], $plan->hidden);
        self::assertSame(RuleEvaluation::OUT_OF_SCHEDULE, $plan->evaluations[0]->reason);
    }

    /**
     * @dataProvider schedules
     */
    public function testAScheduleIsInclusiveOfBothItsEnds(?string $start, ?string $end, bool $inForce): void
    {
        // One fixed instant for all three dates, so a boundary is tested rather than the clock.
        $now = new DateTime('2026-06-15 12:00:00', new DateTimeZone('UTC'));

        $rule = new SearchRule([
            'dateStart' => $start !== null ? (clone $now)->modify($start) : null,
            'dateEnd' => $end !== null ? (clone $now)->modify($end) : null,
        ]);

        self::assertSame($inForce, $rule->isInForce($now));
    }

    /**
     * @return array<string,array{string|null,string|null,bool}>
     */
    public static function schedules(): array
    {
        return [
            'no dates' => [null, null, true],
            'exactly at the start' => ['+0 seconds', '+1 hour', true],
            'exactly at the end' => ['-1 hour', '+0 seconds', true],
            'start only, started' => ['-1 hour', null, true],
            'start only, not yet' => ['+1 hour', null, false],
            'end only, running' => [null, '+1 hour', true],
            'end only, over' => [null, '-1 hour', false],
            'both, inside' => ['-1 hour', '+1 hour', true],
            'both, before' => ['+1 hour', '+2 hours', false],
            'both, after' => ['-2 hours', '-1 hour', false],
            'one second past the end' => ['-1 hour', '-1 second', false],
            'one second before the start' => ['+1 second', '+1 hour', false],
        ];
    }

    // ---------------------------------------------------------------- site scope

    public function testASiteSpecificRuleNeverReachesAnotherSite(): void
    {
        $this->rule(['siteId' => 1], [$this->hide(5)], $this->everySite);

        $result = $this->search([[5, 10.0, 1], [5, 9.0, 3], [6, 8.0, 3]], index: $this->everySite);

        // The same element in site 3 is untouched; only its site 1 row was removed.
        self::assertSame([[5, 3], [6, 3]], $this->rows($result));
        self::assertSame(2, $result->total);
    }

    public function testASiteSpecificRuleIsNotEvenReadForAnotherSitesSearch(): void
    {
        $this->rule(['siteId' => 3], [$this->hide(5)], $this->everySite);

        $result = $this->search([[5, 10.0, 1]], ['siteId' => 1], index: $this->everySite);

        self::assertSame([[5, 1]], $this->rows($result));
        self::assertSame([], $result->rules);
    }

    public function testASiteSpecificRuleAppliesToItsOwnSitesSearch(): void
    {
        $this->rule(['siteId' => 1], [$this->hide(5)], $this->everySite);

        $result = $this->search([[5, 10.0, 1], [6, 9.0, 1]], ['siteId' => 1], index: $this->everySite);

        self::assertSame([[6, 1]], $this->rows($result));
    }

    public function testARuleWithNoSiteOfItsOwnReachesEverySiteTheSearchCovers(): void
    {
        $this->rule([], [$this->hide(5)], $this->everySite);

        $result = $this->search([[5, 10.0, 1], [5, 9.0, 3], [6, 8.0, 3]], index: $this->everySite);

        self::assertSame([[6, 3]], $this->rows($result));
    }

    public function testASiteSpecificBoostOnlyMovesThatSitesResult(): void
    {
        $this->rule(['siteId' => 3], [$this->boost(5, 100.0)], $this->everySite);

        $result = $this->search([[9, 10.0, 1], [5, 1.0, 1], [5, 1.0, 3]], index: $this->everySite);

        self::assertSame([[5, 3], [9, 1], [5, 1]], $this->rows($result));
    }

    public function testASiteSpecificPinPlacesOnlyThatSitesResult(): void
    {
        $this->rule(['siteId' => 3], [$this->pin(7, 1)], $this->everySite);

        $result = $this->search([[9, 10.0, 1], [9, 9.0, 3]], index: $this->everySite);

        self::assertSame([[7, 3], [9, 1], [9, 3]], $this->rows($result));
        self::assertTrue($result->hits[0]->pinned);
    }

    public function testAnActionOnAnElementTypeTheIndexNoLongerSearchesIsRefused(): void
    {
        $this->rule([], [$this->hide(5)]);

        // The index stops searching entries, so a rule that names one has nothing left to act on.
        $this->index->setFields([]);

        $plan = $this->engine->plan(SearchQuery::create('siteSearch', 'shoes'), $this->index);

        self::assertSame([], $plan->hidden);
        self::assertSame('elementTypeNotSearchedByIndex', $plan->evaluations[0]->effects[0]['outcome']);
    }

    public function testAPlacementWithNoSiteToLandInIsRefusedRatherThanGuessed(): void
    {
        // An index covering every site and a rule naming none leaves no site to place the result in.
        $rule = new SearchRule(['id' => 1, 'indexId' => 1, 'name' => 'No site', 'matchValue' => 'shoes']);
        $rule->setActions([new RuleAction([
            'type' => RuleActionType::Promote,
            'elementId' => 7,
            'elementType' => self::ENTRY,
        ])]);
        $this->rules->rules = [$rule];

        $plan = $this->engine->plan(SearchQuery::create('siteSearch', 'shoes'), $this->everySite);

        self::assertSame([], $plan->promoted);
        self::assertSame('noSiteToPlaceIn', $plan->evaluations[0]->effects[0]['outcome']);
    }

    // ---------------------------------------------------------------- actions

    public function testBoostMovesAResultUpWithoutTouchingTheProviderScore(): void
    {
        $this->rule([], [$this->boost(3, 100.0)]);

        $result = $this->search([[1, 10.0], [2, 8.0], [3, 1.0]]);

        self::assertSame([3, 1, 2], $result->getElementIds());
        self::assertSame(1.0, $result->hits[0]->score);
        self::assertSame(100.0, $result->hits[0]->scoreAdjustment);
        self::assertSame(101.0, $result->hits[0]->getFinalScore());
    }

    public function testBoostLiftsAResultRankedWellBeyondThePage(): void
    {
        $organic = [];

        for ($id = 1; $id <= 60; $id++) {
            $organic[] = [$id, 100.0 - $id];
        }

        $this->rule([], [$this->boost(55, 900.0)]);

        // A page of two could never have read a result ranked 55th, so it has to be asked for.
        $result = $this->search($organic, ['limit' => 2]);

        self::assertSame([55, 1], $result->getElementIds());
        self::assertSame(60, $result->total, 'moving a result must not change how many there are');
    }

    public function testABoostOnAResultTheSearchNeverMatchedAddsNothing(): void
    {
        $this->rule([], [$this->boost(999, 900.0)]);

        $result = $this->search([[1, 10.0], [2, 9.0]]);

        self::assertSame([1, 2], $result->getElementIds());
        self::assertSame(2, $result->total);
    }

    public function testBuryMovesAResultDown(): void
    {
        $this->rule([], [$this->bury(1, 50.0)]);

        self::assertSame([2, 3, 1], $this->search([[1, 10.0], [2, 8.0], [3, 7.0]])->getElementIds());
    }

    public function testHideLeavesAResultOutOfTheSearchEntirely(): void
    {
        $this->rule([], [$this->hide(2)]);

        $query = SearchQuery::create('siteSearch', 'shoes');
        $plan = $this->engine->plan($query, $this->index);

        self::assertSame(
            [['elementId' => 2, 'siteId' => 1]],
            $this->engine->windowQuery($query, $plan)->getExcludedElements(),
        );
    }

    public function testPinPlacesAResultAtItsPosition(): void
    {
        $this->rule([], [$this->pin(3, 2)]);

        self::assertSame([1, 3, 2], $this->search([[1, 10.0], [2, 8.0], [3, 1.0]])->getElementIds());
    }

    public function testAPlacedResultIsAskedForOnceAndCountedOnce(): void
    {
        $this->rule([], [$this->pin(3, 1)]);

        // Element 3 is organic, but the provider is told to leave it out so the rule can place it.
        $result = $this->search([[1, 10.0], [2, 8.0], [3, 1.0]]);

        self::assertSame([3, 1, 2], $result->getElementIds());
        self::assertSame(3, $result->total);
        self::assertCount(1, array_filter($result->getElementIds(), static fn(int $id) => $id === 3));
    }

    public function testPromotingAResultTheSearchNeverMatchedAddsItExactlyOnce(): void
    {
        $this->rule([], [$this->promote(99)]);

        $result = $this->search([[1, 10.0], [2, 8.0]]);

        self::assertSame([99, 1, 2], $result->getElementIds());
        self::assertTrue($result->hits[0]->promoted);
        self::assertSame(self::ENTRY, $result->hits[0]->elementType);
        self::assertSame(3, $result->total);
    }

    public function testARedirectIsOfferedAndTheSearchStillRuns(): void
    {
        $this->rule(['priority' => 1], [$this->redirect('/help')]);
        $this->rule(['priority' => 10], [$this->redirect('/support')]);

        $result = $this->search([[1, 10.0]]);

        self::assertSame('/support', $result->redirect);
        self::assertSame([1], $result->getElementIds());
    }

    public function testASiteSpecificRedirectNeverSendsASearchCoveringEverySite(): void
    {
        $this->rule(['siteId' => 1], [$this->redirect('/support')], $this->everySite);

        $result = $this->search([[1, 10.0, 1], [2, 9.0, 3]], index: $this->everySite);

        // The search spans both sites; one site's rule does not get to redirect all of it.
        self::assertNull($result->redirect);
        self::assertSame('redirectNarrowerThanSearch', $result->rules[0]->effects[0]['outcome']);
        self::assertFalse($result->rules[0]->effects[0]['applied']);
    }

    public function testASiteSpecificRedirectSendsASearchConfinedToThatSite(): void
    {
        $this->rule(['siteId' => 1], [$this->redirect('/support')], $this->everySite);

        $result = $this->search([[1, 10.0, 1]], ['siteId' => 1], index: $this->everySite);

        self::assertSame('/support', $result->redirect);
    }

    public function testASiteSpecificRedirectNeverSendsASearchNamingSeveralSites(): void
    {
        $this->rule(['siteId' => 1], [$this->redirect('/support')], $this->everySite);

        $result = $this->search([[1, 10.0, 1], [2, 9.0, 3]], ['sites' => [1, 3]], index: $this->everySite);

        // A list of sites is still more than the one the rule was written for.
        self::assertNull($result->redirect);
        self::assertSame('redirectNarrowerThanSearch', $result->rules[0]->effects[0]['outcome']);
    }

    public function testARuleCannotActOnASiteTheSearchDidNotName(): void
    {
        $this->rule(['siteId' => 3], [$this->hide(5)], $this->everySite);

        $result = $this->search([[5, 10.0, 1]], ['sites' => [1, 2]], index: $this->everySite);

        self::assertSame([5], $result->getElementIds());
        self::assertSame('siteOutsideSearchScope', $result->rules[0]->effects[0]['outcome']);
    }

    public function testARedirectFromARuleCoveringTheWholeIndexAlwaysApplies(): void
    {
        // The index covers one site, so a rule naming that site covers the whole search.
        $this->rule(['siteId' => 1], [$this->redirect('/support')]);

        self::assertSame('/support', $this->search([[1, 10.0]])->redirect);
    }

    public function testARedirectAloneLeavesTheSearchUntouched(): void
    {
        $this->rule([], [$this->redirect('/support')]);

        $query = SearchQuery::create('siteSearch', 'shoes', ['limit' => 5, 'page' => 3]);
        $plan = $this->engine->plan($query, $this->index);

        self::assertFalse($plan->affectsSearch());
        self::assertSame($query, $this->engine->windowQuery($query, $plan));
    }

    // ---------------------------------------------------------------- conflicts

    public function testTheFirstRuleToClaimAResultKeepsIt(): void
    {
        $cases = [
            // [higher priority action, lower priority action, what the higher one did]
            'hide beats promote' => [$this->hide(5), $this->promote(5), 'hidden'],
            'hide beats pin' => [$this->hide(5), $this->pin(5, 1), 'hidden'],
            'hide beats boost' => [$this->hide(5), $this->boost(5, 10.0), 'hidden'],
            'hide beats bury' => [$this->hide(5), $this->bury(5, 10.0), 'hidden'],
            'pin beats hide' => [$this->pin(5, 1), $this->hide(5), 'pins'],
            'promote beats hide' => [$this->promote(5), $this->hide(5), 'promoted'],
            'pin beats promote' => [$this->pin(5, 1), $this->promote(5), 'pins'],
            'promote beats pin' => [$this->promote(5), $this->pin(5, 1), 'promoted'],
        ];

        foreach ($cases as $label => [$winner, $loser, $expected]) {
            $this->rules->rules = [];
            $this->rule(['priority' => 10], [$winner]);
            $this->rule(['priority' => 1], [$loser]);

            $plan = $this->engine->plan(SearchQuery::create('siteSearch', 'shoes'), $this->index);

            self::assertCount(1, $plan->$expected, $label);
            self::assertSame(1, $plan->evaluations[0]->ruleId, $label);
            self::assertTrue($plan->evaluations[0]->effects[0]['applied'], $label);
            self::assertFalse($plan->evaluations[1]->effects[0]['applied'], $label);
            self::assertStringStartsWith('claimedBy:', (string)$plan->evaluations[1]->effects[0]['outcome'], $label);
        }
    }

    /**
     * A rule naming no site governs every site the search covers; one naming a site governs only
     * that site. Where they meet, the higher priority decides that site and the other rule still
     * governs the rest.
     *
     * @dataProvider globalAgainstSiteSpecific
     * @param array<int,array{0:int,1:int}> $expected Element and site, in order.
     */
    public function testAGlobalRuleAndASiteSpecificRuleEachGovernTheirOwnSites(
        RuleActionType $siteAction,
        int $sitePriority,
        int $globalPriority,
        array $expected,
    ): void {
        $site = $this->action($siteAction, 5);

        if ($siteAction === RuleActionType::Pin) {
            $site->position = 1;
        }

        $this->rule(['siteId' => 1, 'priority' => $sitePriority], [$site], $this->everySite);
        $this->rule(['priority' => $globalPriority], [$this->hide(5)], $this->everySite);

        // Element 5 exists in both sites; element 9 is only there to rank against.
        $result = $this->search([[9, 10.0, 1], [5, 9.0, 1], [9, 8.0, 3], [5, 7.0, 3]], index: $this->everySite);

        self::assertSame($expected, $this->rows($result));
    }

    /**
     * @return array<string,array{RuleActionType,int,int,array<int,array{0:int,1:int}>}>
     */
    public static function globalAgainstSiteSpecific(): array
    {
        return [
            // The site rule wins site 1; the global hide still clears element 5 out of site 3.
            'site pin outranks global hide' => [RuleActionType::Pin, 10, 5, [[5, 1], [9, 1], [9, 3]]],
            'site promote outranks global hide' => [RuleActionType::Promote, 10, 5, [[5, 1], [9, 1], [9, 3]]],
            'site hide outranks global hide' => [RuleActionType::Hide, 10, 5, [[9, 1], [9, 3]]],
            // The global hide got there first, so it decides every site and the site rule is refused.
            'global hide outranks site pin' => [RuleActionType::Pin, 5, 10, [[9, 1], [9, 3]]],
            'global hide outranks site promote' => [RuleActionType::Promote, 5, 10, [[9, 1], [9, 3]]],
            'global hide outranks site hide' => [RuleActionType::Hide, 5, 10, [[9, 1], [9, 3]]],
        ];
    }

    public function testAResultClaimedInOneSiteIsNotAlsoMovedThereByAGlobalAdjustment(): void
    {
        $this->rule(['siteId' => 1, 'priority' => 10], [$this->pin(5, 1)], $this->everySite);
        $this->rule(['priority' => 5], [$this->boost(5, 900.0)], $this->everySite);

        $result = $this->search([[9, 10.0, 1], [5, 9.0, 1], [9, 8.0, 3], [5, 7.0, 3]], index: $this->everySite);

        // Site 1's copy is pinned, so it is placed once and never also merged back as a boosted one.
        self::assertSame([[5, 1], [5, 3], [9, 1], [9, 3]], $this->rows($result));
        self::assertSame(4, $result->total);
    }

    public function testASiteSpecificRuleNeverBlocksAnotherSitesRuleForTheSameElement(): void
    {
        $this->rule(['siteId' => 1, 'priority' => 10], [$this->pin(5, 1)], $this->everySite);
        $this->rule(['siteId' => 3, 'priority' => 5], [$this->hide(5)], $this->everySite);

        $result = $this->search([[9, 10.0, 1], [5, 9.0, 1], [9, 8.0, 3], [5, 7.0, 3]], index: $this->everySite);

        // Site 1 pins its copy; site 3 hides its own. Neither reaches the other.
        self::assertSame([[5, 1], [9, 1], [9, 3]], $this->rows($result));
    }

    public function testTwoPinsCannotShareAPosition(): void
    {
        $this->rule(['priority' => 10], [$this->pin(7, 1)]);
        $this->rule(['priority' => 1], [$this->pin(8, 1)]);

        $result = $this->search([[1, 10.0]]);

        self::assertSame([7, 1], $result->getElementIds());
        self::assertSame('positionTakenBy:1', $result->rules[1]->effects[0]['outcome']);
    }

    public function testTwoPromotionsOfTheSameResultResolveToOne(): void
    {
        $this->rule(['priority' => 10], [$this->promote(7)]);
        $this->rule(['priority' => 1], [$this->promote(7)]);

        $result = $this->search([[1, 10.0]]);

        self::assertSame([7, 1], $result->getElementIds());
        self::assertSame(2, $result->total);
    }

    public function testAdjustmentsFromSeveralRulesAccumulate(): void
    {
        $this->rule(['priority' => 10], [$this->boost(3, 5.0)]);
        $this->rule(['priority' => 1], [$this->boost(3, 6.0)]);

        $result = $this->search([[1, 10.0], [3, 1.0]]);

        self::assertSame([3, 1], $result->getElementIds());
        self::assertSame(11.0, $result->hits[0]->scoreAdjustment);
    }

    public function testABoostAndABuryOnOneResultCancelOut(): void
    {
        $this->rule(['priority' => 10], [$this->boost(3, 50.0)]);
        $this->rule(['priority' => 1], [$this->bury(3, 50.0)]);

        $result = $this->search([[1, 10.0], [3, 1.0]]);

        self::assertSame(0.0, $result->hits[1]->scoreAdjustment);
        self::assertSame([1, 3], $result->getElementIds());
    }

    public function testRulesOfEqualPriorityAreSettledOldestFirst(): void
    {
        $this->rule(['priority' => 5], [$this->pin(7, 1)]);
        $this->rule(['priority' => 5], [$this->pin(8, 1)]);

        self::assertSame([7, 1], $this->search([[1, 10.0]])->getElementIds());
    }

    public function testOneRuleCannotClaimTheSameResultTwice(): void
    {
        $this->rule([], [$this->pin(5, 1), $this->boost(5, 100.0), $this->hide(5)]);

        $result = $this->search([[1, 10.0]]);

        self::assertSame([5, 1], $result->getElementIds());
        self::assertSame([true, false, false], array_column($result->rules[0]->effects, 'applied'));
    }

    // ---------------------------------------------------------------- pagination and totals

    public function testAPageIsCutOutAfterTheRulesHaveArrangedTheWholeList(): void
    {
        $this->rule([], [$this->pin(5, 1)]);

        $result = $this->search([[1, 5.0], [2, 4.0], [3, 3.0], [4, 2.0]], ['limit' => 2, 'offset' => 2]);

        // The pin pushed everything down one, so the second page starts one result earlier.
        self::assertSame([2, 3], $result->getElementIds());
        self::assertSame(5, $result->total);
    }

    public function testADeepPageIsReadStraightRatherThanAssembled(): void
    {
        $this->rule([], [$this->pin(5, 1)]);

        $query = SearchQuery::create('siteSearch', 'shoes', ['limit' => 10, 'offset' => 500]);
        $plan = $this->engine->plan($query, $this->index);

        self::assertFalse($plan->assembles);
        self::assertSame(499, $plan->windowOffset);
        self::assertSame(10, $plan->windowLimit);
    }
    /**
     * @dataProvider placedBoundaries
     */
    public function testAPageEitherSideOfTheFurthestPlacedResultHoldsTheSameList(int $offset): void
    {
        $organic = [];

        for ($id = 1; $id <= 40; $id++) {
            $organic[] = [$id, 100.0 - $id];
        }

        $this->rules->rules = [];
        $this->rule(['priority' => 10], [$this->pin(901, 1)]);
        $this->rule(['priority' => 5], [$this->pin(902, 6)]);
        $this->rule(['priority' => 1], [$this->promote(903)]);

        $whole = $this->search($organic, ['limit' => 40]);
        $page = $this->search($organic, ['limit' => 3, 'offset' => $offset]);

        // However the page was read, it holds exactly what the whole assembled list holds there.
        self::assertSame(
            array_slice($whole->getElementIds(), $offset, 3),
            $page->getElementIds(),
            "offset {$offset}",
        );
        self::assertSame($whole->total, $page->total, "offset {$offset}");
    }

    /**
     * @return array<string,array{int}>
     */
    public static function placedBoundaries(): array
    {
        // Three placed results, the furthest pinned at position 6, so the reach is 6.
        return [
            'first page' => [0],
            'just before the furthest placement' => [4],
            'exactly at the furthest placement' => [5],
            'immediately after it' => [6],
            'well past it' => [7],
            'deep' => [30],
        ];
    }

    public function testHidingChangesTheTotalWithoutBeingCountedTwice(): void
    {
        $this->rule([], [$this->hide(2), $this->hide(3)]);

        $result = $this->search([[1, 10.0], [2, 8.0], [3, 7.0], [4, 6.0]]);

        self::assertSame([1, 4], $result->getElementIds());
        self::assertSame(2, $result->total);
    }

    public function testHidingReachesAResultFarBeyondThePageThatWasAsked(): void
    {
        $organic = [];

        for ($id = 1; $id <= 200; $id++) {
            $organic[] = [$id, 200.0 - $id];
        }

        $this->rule([], [$this->hide(199)]);

        $first = $this->search($organic, ['limit' => 5]);
        $last = $this->search($organic, ['limit' => 5, 'offset' => 195]);

        self::assertSame(199, $first->total);
        self::assertNotContains(199, $last->getElementIds());
        self::assertSame([196, 197, 198, 200], $last->getElementIds());
    }

    public function testAPageBeyondTheEndIsEmptyRatherThanWrong(): void
    {
        $this->rule([], [$this->pin(5, 1)]);

        self::assertSame([], $this->search([[1, 10.0], [2, 9.0]], ['limit' => 10, 'offset' => 50])->getElementIds());
    }

    public function testHidePinPromoteAndBoostTogetherStayConsistent(): void
    {
        $this->rule(['priority' => 40], [$this->hide(2)]);
        $this->rule(['priority' => 30], [$this->pin(99, 1)]);
        $this->rule(['priority' => 20], [$this->promote(98)]);
        $this->rule(['priority' => 10], [$this->boost(4, 100.0)]);

        $result = $this->search([[1, 10.0], [2, 9.0], [3, 8.0], [4, 1.0]]);

        self::assertSame([99, 98, 4, 1, 3], $result->getElementIds());
        self::assertSame(5, $result->total);
    }

    // ---------------------------------------------------------------- ordering

    public function testScoreAdjustmentsAreRecordedButSkippedUnderACustomSort(): void
    {
        $this->rule([], [$this->boost(3, 100.0)]);

        $result = $this->search([[1, 10.0], [2, 8.0], [3, 1.0]], ['orderBy' => 'title asc']);

        self::assertSame([1, 2, 3], $result->getElementIds());
        self::assertSame(100.0, $result->hits[2]->scoreAdjustment);
        self::assertSame(RulePlan::SKIPPED_CUSTOM_SORT, $this->lastPlan->reorderSkipped);
        self::assertSame(RulePlan::SKIPPED_CUSTOM_SORT, $result->rules[0]->effects[1]['outcome']);
    }

    public function testPlacementStillAppliesUnderACustomSort(): void
    {
        $this->rule([], [$this->pin(5, 1)]);

        self::assertSame([5, 1, 2], $this->search([[1, 10.0], [2, 8.0]], ['orderBy' => 'title asc'])->getElementIds());
    }

    public function testAdjustmentsAreSkippedBeyondTheReorderingWindowRatherThanMisapplied(): void
    {
        $this->rule([], [$this->boost(3, 100.0)]);

        $query = SearchQuery::create('siteSearch', 'shoes', ['limit' => 10, 'offset' => RuleEngine::REORDER_LIMIT]);
        $plan = $this->engine->plan($query, $this->index);

        self::assertFalse($plan->reordered);
        self::assertSame(RulePlan::SKIPPED_BEYOND_WINDOW, $plan->reorderSkipped);
        self::assertFalse($plan->assembles);
        self::assertSame(RuleEngine::REORDER_LIMIT, $plan->windowOffset);
    }

    /**
     * Giving up the reordering must not also give up assembling: a page reaching into the placed
     * results still has to be built from the top, or the pins below it are read straight past.
     */
    public function testAPageInsideThePlacedResultsIsStillAssembledWhenReorderingIsGivenUp(): void
    {
        $this->rule([], [$this->pin(901, 9), $this->pin(902, 10), $this->boost(3, 100.0)]);

        // Reaches past the reordering window, while the page itself sits inside the pinned results.
        $query = SearchQuery::create('siteSearch', 'shoes', ['limit' => RuleEngine::REORDER_LIMIT, 'offset' => 1]);
        $plan = $this->engine->plan($query, $this->index);

        self::assertFalse($plan->reordered);
        self::assertSame(RulePlan::SKIPPED_BEYOND_WINDOW, $plan->reorderSkipped);

        self::assertTrue($plan->assembles, 'A page inside the placed results has to be assembled.');
        self::assertSame(0, $plan->windowOffset);
        self::assertSame(RuleEngine::REORDER_LIMIT + 1, $plan->windowLimit);

        // Shifting by the placed count here would have asked the provider for a negative offset.
        self::assertGreaterThanOrEqual(0, (int)$this->engine->windowQuery($query, $plan)->offset);
    }

    // ---------------------------------------------------------------- explanation

    public function testEveryRuleConsideredIsCarriedOnTheResultWithWhyAndWhat(): void
    {
        $this->rule(['name' => 'Applies', 'priority' => 3], [$this->boost(1, 5.0)]);
        $this->rule(['name' => 'Does not', 'matchValue' => 'boots'], [$this->hide(1)]);

        $result = $this->search([[1, 1.0]]);

        self::assertCount(2, $result->rules);

        $applied = $result->rules[0];
        self::assertTrue($applied->matched);
        self::assertSame('Applies', $applied->ruleName);
        self::assertSame(3, $applied->priority);
        self::assertSame('exact', $applied->matchType);
        self::assertSame('shoes', $applied->matchValue);
        // The rule declared no site of its own, which is what null means here; the site each action
        // settled on is recorded against the action instead.
        self::assertNull($applied->siteId);
        self::assertSame(
            ['action' => 'boost', 'elementId' => 1, 'siteId' => 1, 'amount' => 5.0,
                'position' => null, 'applied' => true, 'outcome' => 'applied', ],
            $applied->effects[0],
        );

        self::assertFalse($result->rules[1]->matched);
        self::assertSame(RuleEvaluation::NO_MATCH, $result->rules[1]->reason);
        self::assertSame([], $result->rules[1]->effects);
    }

    public function testWithholdingAPlacementInOneSiteLeavesTheSameElementInAnotherAlone(): void
    {
        // The very same element ID, placed by a rule in each site.
        $this->rule(['siteId' => 1, 'priority' => 10], [$this->pin(7, 1)], $this->everySite);
        $this->rule(['siteId' => 3, 'priority' => 5], [$this->pin(7, 2)], $this->everySite);

        $result = $this->search([[1, 10.0, 1]], index: $this->everySite);
        self::assertSame([[7, 1], [7, 3], [1, 1]], $this->rows($result));

        // Only the site 1 copy turns out to be unviewable.
        foreach ($result->hits as $hit) {
            $hit->element = ($hit->elementId === 7 && $hit->siteId === 1) ? null : $this->loaded();
        }

        $this->engine->reconcilePlacements($this->lastPlan);

        $withheld = $this->lastPlan->evaluations[0]->effects;
        $kept = $this->lastPlan->evaluations[1]->effects;

        foreach ($withheld as $effect) {
            self::assertNull($effect['elementId'], 'the site 1 placement must not be named');
            self::assertSame(RulePlan::NOT_VIEWABLE, $effect['outcome']);
        }

        foreach ($kept as $effect) {
            self::assertSame(7, $effect['elementId'], 'the site 3 placement was shown and stays named');
            self::assertNotSame(RulePlan::NOT_VIEWABLE, $effect['outcome'] ?? null);
        }
    }

    public function testWithholdingIsSettledPerSiteInEitherDirection(): void
    {
        $this->rule(['siteId' => 1, 'priority' => 10], [$this->promote(7)], $this->everySite);
        $this->rule(['siteId' => 3, 'priority' => 5], [$this->promote(7)], $this->everySite);

        $result = $this->search([[1, 10.0, 1]], index: $this->everySite);

        // This time the site 3 copy is the unviewable one.
        foreach ($result->hits as $hit) {
            $hit->element = ($hit->elementId === 7 && $hit->siteId === 3) ? null : $this->loaded();
        }

        $this->engine->reconcilePlacements($this->lastPlan);

        self::assertSame(7, $this->lastPlan->evaluations[0]->effects[0]['elementId']);
        self::assertNull($this->lastPlan->evaluations[1]->effects[0]['elementId']);
    }

    public function testAPlacedResultCarriesWhichRulePutItThere(): void
    {
        $this->rule([], [$this->pin(5, 2)]);

        $result = $this->search([[1, 10.0]]);

        self::assertSame(
            ['action' => 'pin', 'ruleId' => 1, 'position' => 2],
            $result->hits[1]->ruleEffects[0],
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Stands in for an element the search managed to load, which is all reconciliation looks at.
     */
    private function loaded(): ElementInterface
    {
        return $this->createStub(ElementInterface::class);
    }

    private function newIndex(?int $siteId): SearchIndex
    {
        $index = new SearchIndex([
            'id' => 1,
            'name' => 'Site Search',
            'handle' => 'siteSearch',
            'provider' => CraftProvider::class,
            'siteId' => $siteId,
        ]);
        // The index has to search entries for a rule to be allowed to name one.
        $index->setFields([new SearchableField(['elementType' => self::ENTRY, 'handle' => 'title', 'weight' => 5])]);

        return $index;
    }

    /**
     * Runs a plan against a stand-in for a provider that honours exclusions, which is the contract
     * a provider has to meet for rules to work at all.
     *
     * @param array<int,array{0:int,1:float,2?:int}> $organic Element ID, score and site.
     * @param array<string,mixed> $params
     */
    private function search(array $organic, array $params = [], ?SearchIndex $index = null): SearchResult
    {
        $index ??= $this->index;
        $query = SearchQuery::create('siteSearch', 'shoes', $params + ['limit' => 20]);
        $plan = $this->lastPlan = $this->engine->plan($query, $index);
        $execution = $this->engine->windowQuery($query, $plan);

        $kept = array_values(array_filter(
            $organic,
            static function(array $row) use ($execution) {
                foreach ($execution->getExcludedElements() as $excluded) {
                    $site = $row[2] ?? 1;

                    if ($excluded['elementId'] === $row[0] && ($excluded['siteId'] === null || $excluded['siteId'] === $site)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        $hit = static fn(array $row) => new SearchHit([
            'elementId' => $row[0],
            'score' => $row[1],
            'siteId' => $row[2] ?? 1,
            'elementType' => self::ENTRY,
        ]);

        $hits = array_map($hit, array_slice($kept, $execution->offset, $execution->limit));

        // The results a boost or a bury moves are held back and asked for by name, which is the
        // second search the search service runs. The stand-in provider answers it the same way.
        $adjusted = [];

        if ($plan->refetchesAdjusted) {
            $wanted = [];

            foreach ($plan->adjustedElements() as $target) {
                $wanted[RulePlan::key($target['elementId'], $target['siteId'])] = true;
            }

            foreach ($organic as $row) {
                $site = $row[2] ?? 1;

                if (!isset($wanted[RulePlan::key($row[0], $site)]) && !isset($wanted[RulePlan::key($row[0], null)])) {
                    continue;
                }

                // A result a rule already removed or placed is accounted for there, not here.
                if ($plan->claimedBy($row[0], $site) !== null) {
                    continue;
                }

                $adjusted[] = $hit($row);
            }
        }

        $result = new SearchResult(['hits' => $hits, 'total' => count($kept)]);
        $this->engine->apply($result, $plan, $query, $execution, $index, $adjusted);

        return $result;
    }

    /**
     * @return array<int,array{0:int,1:int}> Each hit as its element and the site it came from.
     */
    private function rows(SearchResult $result): array
    {
        return array_map(static fn(SearchHit $hit) => [$hit->elementId, (int)$hit->siteId], $result->hits);
    }

    private function utc(string $modifier): DateTime
    {
        return new DateTime($modifier, new DateTimeZone('UTC'));
    }

    /**
     * @param array<string,mixed> $config
     * @param RuleAction[] $actions
     */
    private function rule(array $config = [], array $actions = [], ?SearchIndex $index = null): SearchRule
    {
        $index ??= $this->index;

        $rule = new SearchRule([
            'id' => count($this->rules->rules) + 1,
            'indexId' => 1,
            'name' => 'Test rule',
            'matchType' => RuleMatchType::Exact,
            'matchValue' => 'shoes',
        ]);

        foreach ($config as $attribute => $value) {
            $rule->$attribute = $value;
        }

        // The service settles this when a rule is saved; a unit test stands in for it.
        foreach ($actions as $action) {
            if ($action->type->targetsElement()) {
                $action->siteId = $rule->scopeSiteId($index->siteId);
            }
        }

        $rule->setActions($actions !== [] ? $actions : [$this->boost(1, 1.0)]);
        $this->rules->rules[] = $rule;

        usort($this->rules->rules, static fn(SearchRule $a, SearchRule $b) => [$b->priority, $a->id] <=> [$a->priority, $b->id]);

        return $rule;
    }

    private function hide(int $elementId): RuleAction
    {
        return $this->action(RuleActionType::Hide, $elementId);
    }

    private function promote(int $elementId): RuleAction
    {
        return $this->action(RuleActionType::Promote, $elementId);
    }

    private function pin(int $elementId, int $position): RuleAction
    {
        $action = $this->action(RuleActionType::Pin, $elementId);
        $action->position = $position;

        return $action;
    }

    private function boost(int $elementId, float $amount): RuleAction
    {
        $action = $this->action(RuleActionType::Boost, $elementId);
        $action->amount = $amount;

        return $action;
    }

    private function bury(int $elementId, float $amount): RuleAction
    {
        $action = $this->action(RuleActionType::Bury, $elementId);
        $action->amount = $amount;

        return $action;
    }

    private function redirect(string $value): RuleAction
    {
        return new RuleAction(['type' => RuleActionType::Redirect, 'value' => $value]);
    }

    private function action(RuleActionType $type, int $elementId): RuleAction
    {
        return new RuleAction([
            'type' => $type,
            'elementId' => $elementId,
            'elementType' => self::ENTRY,
        ]);
    }
}
