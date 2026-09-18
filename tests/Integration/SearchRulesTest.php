<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\events\AuthorizationCheckEvent;
use craft\helpers\DateTimeHelper;
use craft\services\Elements;
use DateTime;
use DateTimeZone;
use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\enums\RuleMatchType;
use Tahadudhiya\SearchKit\errors\UnauthorizedQueryException;
use Tahadudhiya\SearchKit\models\RuleAction;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\models\SearchRule;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\services\Rules;
use yii\base\Event;

/**
 * Runs real searches over real content with real rules behind them, so what it asserts about
 * merchandising is what a visitor would actually get.
 */
class SearchRulesTest extends ContentTestCase
{
    /** @var string A word no other content in the project can match. */
    private const TERM = 'zqxmerchandise';

    private SearchIndex $index;

    /** @var array<string,Entry> Entries by the word that distinguishes them. */
    private array $entries = [];

    /** @var SearchRule[] */
    private array $createdRules = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->sectionSiteId(),
        );

        foreach (['alpha', 'beta', 'gamma', 'delta'] as $word) {
            $this->entries[$word] = $this->createEntry(ucfirst($word) . ' ' . self::TERM);
        }

        $this->plugin()->getIndexing()->processPending($this->index);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRules as $rule) {
            $this->plugin()->getRules()->deleteRule($rule);
        }

        $this->createdRules = [];
        $this->entries = [];

        parent::tearDown();
    }

    public function testARuleRoundTripsThroughTheDatabaseWithItsActions(): void
    {
        $rule = $this->rule(['priority' => 7], [
            $this->pin('alpha', 2),
            new RuleAction(['type' => RuleActionType::Redirect, 'value' => '/support']),
        ]);

        $read = $this->freshRules()->getRuleById((int)$rule->id);

        self::assertNotNull($read);
        self::assertSame(7, $read->priority);
        self::assertSame(RuleMatchType::Exact, $read->matchType);
        self::assertCount(1, $read->getActionsOfType(RuleActionType::Pin));

        $pin = $read->getActionsOfType(RuleActionType::Pin)[0];
        self::assertSame(2, $pin->position);
        self::assertSame(Entry::class, $pin->elementType);
        // The service settles the site from the rule and its index, not from what was posted.
        self::assertSame($this->sectionSiteId(), $pin->siteId);

        $redirect = $read->getActionsOfType(RuleActionType::Redirect)[0];
        self::assertSame('/support', $redirect->value);
        self::assertNull($redirect->elementId);
        self::assertNull($redirect->siteId);
    }

    public function testAForgedTargetIsRefusedBeforeItCanReachASearch(): void
    {
        $forged = [
            'an element that does not exist' => $this->action(RuleActionType::Hide, 99999999),
            'an element class that is not one' => new RuleAction([
                'type' => RuleActionType::Hide,
                'elementId' => $this->id('alpha'),
                'elementType' => 'Evil\\Payload',
            ]),
        ];

        foreach ($forged as $label => $action) {
            $rule = new SearchRule([
                'indexId' => $this->index->id,
                'name' => 'Forged',
                'matchType' => RuleMatchType::Exact,
                'matchValue' => self::TERM,
            ]);
            $rule->setActions([$action]);

            self::assertFalse($this->plugin()->getRules()->saveRule($rule), $label);
            self::assertNull($rule->id, $label);
        }
    }

    public function testATargetOutsideTheIndexIsRefused(): void
    {
        $category = Category::find()->siteId($this->sectionSiteId())->one();

        if ($category === null) {
            self::markTestSkipped('This project has no category to target.');
        }

        $rule = new SearchRule([
            'indexId' => $this->index->id,
            'name' => 'Wrong kind',
            'matchType' => RuleMatchType::Exact,
            'matchValue' => self::TERM,
        ]);
        $rule->setActions([new RuleAction([
            'type' => RuleActionType::Hide,
            'elementId' => $category->id,
            'elementType' => Category::class,
        ])]);

        // The index searches entries only, so a category is not something its rules may name.
        self::assertFalse($this->plugin()->getRules()->saveRule($rule));
        self::assertNotEmpty($rule->getErrors('actions'));
    }

    public function testARuleForASiteItsIndexDoesNotCoverIsRefused(): void
    {
        $other = $this->aSiteOtherThan($this->sectionSiteId());

        $rule = new SearchRule([
            'indexId' => $this->index->id,
            'siteId' => $other,
            'name' => 'Wrong site',
            'matchType' => RuleMatchType::Exact,
            'matchValue' => self::TERM,
        ]);
        $rule->setActions([$this->hide('alpha')]);

        // The index covers one site only, so a rule for another could never govern anything.
        self::assertFalse($this->plugin()->getRules()->saveRule($rule));
        self::assertNotEmpty($rule->getErrors('siteId'));
    }

    public function testARuleThatPlacesAResultStopsTheSearchBeingCorrectedOutFromUnderIt(): void
    {
        $featured = $this->createEntry('Entirely unrelated feature');
        $this->plugin()->getIndexing()->processPending($this->index);

        // The typo matches nothing on its own, and the promoted result is held back from the
        // provider, so without care the search would look empty and be corrected to another word.
        $this->rule(['matchValue' => $this->typo()], [$this->promote((int)$featured->id)]);

        $result = $this->search($this->typo());

        self::assertFalse($result->wasCorrected());
        self::assertSame([(int)$featured->id], $result->getElementIds());
        self::assertSame(1, $result->total);
    }

    public function testASearchWithNothingPlacedIsStillCorrected(): void
    {
        $this->rule(['matchValue' => $this->typo()], [$this->hide('beta')]);

        // Nothing is placed, so an empty search is still offered the correction it always was.
        self::assertTrue($this->search($this->typo())->wasCorrected());
    }

    public function testADeletedTargetIsRefused(): void
    {
        $doomed = $this->createEntry('Doomed ' . self::TERM);
        $id = (int)$doomed->id;
        Craft::$app->getElements()->deleteElement($doomed);

        $rule = new SearchRule([
            'indexId' => $this->index->id,
            'name' => 'Deleted target',
            'matchType' => RuleMatchType::Exact,
            'matchValue' => self::TERM,
        ]);
        $rule->setActions([$this->action(RuleActionType::Hide, $id)]);

        self::assertFalse($this->plugin()->getRules()->saveRule($rule));
    }

    public function testRuleTextIsNormalizedTheWayAQueryIs(): void
    {
        $rule = $this->rule(['matchValue' => '  Zqxmerchandise  '], [$this->hide('alpha')]);

        self::assertSame(self::TERM, $rule->matchValue);
        self::assertNotContains($this->id('alpha'), $this->search(strtoupper(self::TERM))->getElementIds());
    }

    /**
     * @dataProvider matchTypes
     */
    public function testEveryMatchTypeGovernsARealSearch(RuleMatchType $type, string $value): void
    {
        $this->rule(['matchType' => $type, 'matchValue' => $value], [$this->hide('beta')]);

        $result = $this->search(self::TERM);

        self::assertNotContains($this->id('beta'), $result->getElementIds());
        self::assertCount(1, $result->getMatchedRules());
    }

    /**
     * @return array<string,array{RuleMatchType,string}>
     */
    public static function matchTypes(): array
    {
        return [
            'exact' => [RuleMatchType::Exact, self::TERM],
            'contains' => [RuleMatchType::Contains, substr(self::TERM, 3, 5)],
            'starts with' => [RuleMatchType::StartsWith, substr(self::TERM, 0, 5)],
            'ends with' => [RuleMatchType::EndsWith, substr(self::TERM, -5)],
            'wildcard' => [RuleMatchType::Wildcard, substr(self::TERM, 0, 3) . '*' . substr(self::TERM, -3)],
        ];
    }
    public function testPinPlacesAResultFirstEvenWhenTheSearchRankedItLast(): void
    {
        $this->rule([], [$this->pin('delta', 1)]);

        $result = $this->search(self::TERM);

        self::assertSame($this->id('delta'), $result->getElementIds()[0]);
        self::assertTrue($result->hits[0]->pinned);
    }

    public function testPromoteAddsAResultTheSearchNeverMatched(): void
    {
        $outsider = $this->createEntry('Something else entirely');
        $this->plugin()->getIndexing()->processPending($this->index);

        $this->rule([], [$this->promote($outsider->id)]);

        $result = $this->search(self::TERM);

        self::assertSame((int)$outsider->id, $result->getElementIds()[0]);
        self::assertTrue($result->hits[0]->promoted);
        self::assertNotNull($result->hits[0]->element);
    }

    /**
     * A rule may name content that is published today and is not tomorrow, so what a viewer may see
     * is settled when the search runs and never when the rule was saved.
     *
     * @dataProvider unviewableStates
     */
    public function testAPlacedResultIsWithheldWhenTheViewerMayNotSeeIt(string $state, RuleActionType $type): void
    {
        $target = $this->createEntry(ucfirst($state) . ' target ' . self::TERM);
        $this->plugin()->getIndexing()->processPending($this->index);

        // Saved while the target is still perfectly ordinary content.
        $rule = $this->rule([], [$type === RuleActionType::Pin
            ? $this->pin((int)$target->id, 1)
            : $this->promote((int)$target->id), ]);

        self::assertContains((int)$target->id, $this->search(self::TERM)->getElementIds());
        $published = $this->search(self::TERM)->total;

        $this->makeUnviewable($target, $state);

        foreach (['anonymous' => null, 'admin' => $this->adminUser()] as $who => $identity) {
            Craft::$app->getUser()->setIdentity($identity);

            try {
                $result = $this->search(self::TERM);

                self::assertNotContains((int)$target->id, $result->getElementIds(), "{$state} / {$who}");
                self::assertSame($published - 1, $result->total, "{$state} / {$who}");

                // Nothing may name the withheld result, not even the explanation of why it is absent.
                foreach ($result->rules as $evaluation) {
                    foreach ($evaluation->effects as $effect) {
                        self::assertNotSame((int)$target->id, $effect['elementId'] ?? null, "{$state} / {$who}");
                    }
                }
            } finally {
                Craft::$app->getUser()->setIdentity(null);
            }
        }

        $this->plugin()->getRules()->deleteRule($rule);
        $this->createdRules = [];
    }

    /**
     * @return array<string,array{string,RuleActionType}>
     */
    public static function unviewableStates(): array
    {
        $cases = [];

        foreach (['disabled', 'not yet posted', 'expired', 'disabled for this site', 'trashed'] as $state) {
            foreach ([RuleActionType::Pin, RuleActionType::Promote] as $type) {
                $cases["{$state}, {$type->value}"] = [$state, $type];
            }
        }

        return $cases;
    }

    public function testAPlacedResultIsWithheldFromAViewerCraftRefuses(): void
    {
        $target = $this->createEntry('Refused target ' . self::TERM);
        $target->enabled = false;
        self::assertTrue(Craft::$app->getElements()->saveElement($target));
        $this->plugin()->getIndexing()->processPending($this->index);

        $this->rule([], [$this->pin((int)$target->id, 1)]);

        Craft::$app->getUser()->setIdentity($this->adminUser());
        $denial = static function(AuthorizationCheckEvent $event) use ($target) {
            if ((int)$event->element?->id === (int)$target->id) {
                $event->authorized = false;
            }
        };
        Event::on(Elements::class, Elements::EVENT_AUTHORIZE_VIEW, $denial);

        try {
            // An administrator may search unpublished content, but not content Craft refuses them.
            $this->expectException(UnauthorizedQueryException::class);
            $this->plugin()->getSearch()->search(
                SearchQuery::create($this->index->handle, self::TERM, ['status' => 'disabled']),
            );
        } finally {
            Event::off(Elements::class, Elements::EVENT_AUTHORIZE_VIEW, $denial);
            Craft::$app->getUser()->setIdentity(null);
        }
    }

    public function testAnAdministratorStillReceivesAPlacedResultTheyMaySee(): void
    {
        $target = $this->createEntry('Disabled but allowed ' . self::TERM);
        $target->enabled = false;
        self::assertTrue(Craft::$app->getElements()->saveElement($target));
        $this->plugin()->getIndexing()->processPending($this->index);

        $this->rule([], [$this->pin((int)$target->id, 1)]);
        Craft::$app->getUser()->setIdentity($this->adminUser());

        try {
            $result = $this->plugin()->getSearch()->search(
                SearchQuery::create($this->index->handle, self::TERM, ['status' => 'disabled']),
            );

            // Withholding is about what the viewer may see, not about refusing rules outright.
            self::assertSame([(int)$target->id], $result->getElementIds());
        } finally {
            Craft::$app->getUser()->setIdentity(null);
        }
    }

    private function adminUser(): \craft\elements\User
    {
        $admin = \craft\elements\User::find()->admin(true)->status(null)->one();

        if ($admin === null) {
            self::markTestSkipped('This project has no admin account to search as.');
        }

        return $admin;
    }

    private function makeUnviewable(Entry $entry, string $state): void
    {
        if ($state === 'trashed') {
            self::assertTrue(Craft::$app->getElements()->deleteElement($entry));

            return;
        }

        match ($state) {
            'disabled' => $entry->enabled = false,
            'not yet posted' => $entry->postDate = new DateTime('+30 days'),
            'expired' => $entry->expiryDate = new DateTime('-1 day'),
            'disabled for this site' => $entry->enabledForSite = false,
            default => null,
        };

        self::assertTrue(Craft::$app->getElements()->saveElement($entry));
    }

    public function testBoostLiftsAResultFromBeyondThePageThatWasAsked(): void
    {
        // Ranked last by relevance, and a page of one could never have read it.
        $this->rule([], [$this->boost('delta', 900.0)]);

        $page = $this->search(self::TERM, ['limit' => 1]);
        $whole = $this->search(self::TERM, ['limit' => 20]);

        self::assertSame([$this->id('delta')], $page->getElementIds());
        self::assertSame($this->id('delta'), $whole->getElementIds()[0]);
        self::assertSame($whole->total, $page->total, 'moving a result must not change how many there are');
    }

    public function testBuryPushesAResultOffTheFirstPage(): void
    {
        $this->rule([], [$this->bury('alpha', 900.0)]);

        $whole = $this->search(self::TERM, ['limit' => 20])->getElementIds();

        self::assertSame($this->id('alpha'), $whole[array_key_last($whole)]);
        self::assertNotContains($this->id('alpha'), $this->search(self::TERM, ['limit' => 1])->getElementIds());
    }

    public function testBoostingAResultTheSearchNeverMatchedAddsNothing(): void
    {
        $outsider = $this->createEntry('Nothing in common here');
        $this->plugin()->getIndexing()->processPending($this->index);

        $before = $this->search(self::TERM)->total;
        $this->rule([], [$this->boost((int)$outsider->id, 900.0)]);
        $after = $this->search(self::TERM);

        self::assertSame($before, $after->total);
        self::assertNotContains((int)$outsider->id, $after->getElementIds());
    }
    public function testConflictingRulesAreSettledForTheHigherPriority(): void
    {
        $this->rule(['priority' => 10, 'name' => 'Wins'], [$this->pin('alpha', 1)]);
        $this->rule(['priority' => 1, 'name' => 'Loses'], [$this->pin('beta', 1)]);

        $result = $this->search(self::TERM);

        self::assertSame($this->id('alpha'), $result->getElementIds()[0]);
        self::assertSame(['Wins', 'Loses'], array_column($result->getMatchedRules(), 'ruleName'));
    }

    public function testADisabledRuleChangesNothingButIsStillReported(): void
    {
        $this->rule(['enabled' => false], [$this->hide('beta')]);

        $result = $this->search(self::TERM);

        self::assertContains($this->id('beta'), $result->getElementIds());
        self::assertSame([], $result->getMatchedRules());
        self::assertCount(1, $result->rules);
    }

    public function testAnExpiredRuleChangesNothing(): void
    {
        $this->rule([
            'dateStart' => new DateTime('-2 days', new DateTimeZone('UTC')),
            'dateEnd' => new DateTime('-1 day', new DateTimeZone('UTC')),
        ], [$this->hide('beta')]);

        self::assertContains($this->id('beta'), $this->search(self::TERM)->getElementIds());
    }

    public function testARuleInsideItsScheduleApplies(): void
    {
        $this->rule([
            'dateStart' => new DateTime('-1 day', new DateTimeZone('UTC')),
            'dateEnd' => new DateTime('+1 day', new DateTimeZone('UTC')),
        ], [$this->hide('beta')]);

        self::assertNotContains($this->id('beta'), $this->search(self::TERM)->getElementIds());
    }

    public function testAScheduleEnteredInTheSystemTimezoneIsStoredAndReadBackAsTheSameInstant(): void
    {
        $system = new DateTimeZone(Craft::$app->getTimeZone());

        // The shape Craft's control panel posts a date and time in.
        $entered = DateTimeHelper::toDateTime(['date' => '2026-07-04', 'time' => '09:30'], true);
        self::assertNotFalse($entered);
        self::assertSame($system->getName(), $entered->getTimezone()->getName());

        $rule = $this->rule(['dateStart' => $entered], [$this->hide('beta')]);
        $read = $this->freshRules()->getRuleById((int)$rule->id);

        self::assertNotNull($read);
        self::assertNotNull($read->dateStart);
        // Held in UTC, but naming the very moment the administrator chose.
        self::assertSame('UTC', $read->dateStart->getTimezone()->getName());
        self::assertSame($entered->getTimestamp(), $read->dateStart->getTimestamp());
        self::assertSame('2026-07-04 09:30', $read->dateStart->setTimezone($system)->format('Y-m-d H:i'));
    }

    public function testAScheduleDecidesFromTheInstantRatherThanTheWallClock(): void
    {
        $rule = $this->rule([
            'dateStart' => new DateTime('2026-07-04 09:30', new DateTimeZone(Craft::$app->getTimeZone())),
            'dateEnd' => new DateTime('2026-07-04 11:30', new DateTimeZone(Craft::$app->getTimeZone())),
        ], [$this->hide('beta')]);

        $read = $this->freshRules()->getRuleById((int)$rule->id);
        self::assertNotNull($read);

        $inside = (clone $read->dateStart)->modify('+1 minute');
        $before = (clone $read->dateStart)->modify('-1 minute');
        $after = (clone $read->dateEnd)->modify('+1 minute');

        self::assertTrue($read->isInForce($inside));
        self::assertTrue($read->isInForce($read->dateStart));
        self::assertTrue($read->isInForce($read->dateEnd));
        self::assertFalse($read->isInForce($before));
        self::assertFalse($read->isInForce($after));
    }

    public function testARedirectIsOfferedWithoutStoppingTheSearch(): void
    {
        $this->rule([], [$this->redirect('/support')]);

        $result = $this->search(self::TERM);

        self::assertTrue($result->hasRedirect());
        self::assertSame('/support', $result->redirect);
        self::assertNotEmpty($result->hits);
    }

    public function testARuleForAnotherIndexIsNeverConsidered(): void
    {
        $other = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class, $this->sectionSiteId());
        $this->rule(['indexId' => $other->id], [$this->hide('beta')]);

        $result = $this->search(self::TERM);

        self::assertContains($this->id('beta'), $result->getElementIds());
        self::assertSame([], $result->rules);
    }

    public function testHidingReachesAResultThatNoPageOfTheSearchEverFetched(): void
    {
        // Ranked last by relevance and well past the page size, so nothing SearchKit reads sees it.
        $buried = $this->entries['delta'];
        $before = $this->search(self::TERM, ['limit' => 1]);

        $this->rule([], [$this->hide('delta')]);
        $after = $this->search(self::TERM, ['limit' => 1]);

        self::assertSame($before->total - 1, $after->total);

        for ($page = 1; $page <= $after->total; $page++) {
            self::assertNotContains(
                (int)$buried->id,
                $this->search(self::TERM, ['limit' => 1, 'page' => $page])->getElementIds(),
                "page {$page}",
            );
        }
    }

    public function testEveryPageTogetherHoldsEachResultExactlyOnce(): void
    {
        $this->rule(['priority' => 30], [$this->hide('beta')]);
        $this->rule(['priority' => 20], [$this->pin('delta', 1)]);
        $this->rule(['priority' => 10], [$this->promote('gamma')]);

        $first = $this->search(self::TERM, ['limit' => 1]);
        $seen = [];

        for ($page = 1; $page <= $first->total; $page++) {
            foreach ($this->search(self::TERM, ['limit' => 1, 'page' => $page])->getElementIds() as $id) {
                $seen[] = $id;
            }
        }

        self::assertSame($first->total, count($seen));
        self::assertSame($seen, array_values(array_unique($seen)));
        self::assertNotContains($this->id('beta'), $seen);
        self::assertSame($this->id('delta'), $seen[0]);
        self::assertSame($this->id('gamma'), $seen[1]);
    }

    public function testPlacingAResultTheSearchAlreadyFoundDoesNotChangeTheTotal(): void
    {
        $before = $this->search(self::TERM)->total;

        $this->rule([], [$this->pin('alpha', 1)]);

        self::assertSame($before, $this->search(self::TERM)->total);
    }

    public function testPlacingAResultTheSearchNeverFoundAddsExactlyOne(): void
    {
        $outsider = $this->createEntry('Nothing to do with it');
        $this->plugin()->getIndexing()->processPending($this->index);

        $before = $this->search(self::TERM)->total;
        $this->rule([], [$this->promote((int)$outsider->id)]);

        self::assertSame($before + 1, $this->search(self::TERM)->total);
    }

    public function testACorrectedQueryIsGovernedByTheRulesForTheWordItCorrectedTo(): void
    {
        $cases = [
            'exact' => [RuleMatchType::Exact, self::TERM],
            'contains' => [RuleMatchType::Contains, substr(self::TERM, 2, 6)],
            'wildcard' => [RuleMatchType::Wildcard, substr(self::TERM, 0, 4) . '*'],
        ];

        foreach ($cases as $label => [$matchType, $matchValue]) {
            foreach ($this->createdRules as $rule) {
                $this->plugin()->getRules()->deleteRule($rule);
            }
            $this->createdRules = [];

            $this->rule(['matchType' => $matchType, 'matchValue' => $matchValue], [$this->hide('beta')]);

            $result = $this->search($this->typo());

            self::assertTrue($result->wasCorrected(), $label);
            self::assertNotContains($this->id('beta'), $result->getElementIds(), $label);
        }
    }

    public function testACorrectedQueryCanBePromotedIntoAsWell(): void
    {
        $outsider = $this->createEntry('Quite unrelated');
        $this->plugin()->getIndexing()->processPending($this->index);

        $this->rule([], [$this->promote((int)$outsider->id)]);

        $result = $this->search($this->typo());

        self::assertTrue($result->wasCorrected());
        self::assertSame((int)$outsider->id, $result->getElementIds()[0]);
    }

    public function testAQueryThatNeededNoCorrectionIsGovernedByItsOwnRules(): void
    {
        $this->rule([], [$this->hide('beta')]);

        $result = $this->search(self::TERM);

        self::assertFalse($result->wasCorrected());
        self::assertNotContains($this->id('beta'), $result->getElementIds());
    }

    public function testARuleWrittenForATypoDoesNotGovernTheCorrectedSearch(): void
    {
        // Rules govern the query the search ran, and a corrected search ran the corrected word. A
        // rule for the misspelling is reported as considered and not matched, rather than half
        // applied to results it was never written for.
        $this->rule(['matchValue' => $this->typo()], [$this->hide('beta')]);

        $result = $this->search($this->typo());

        self::assertTrue($result->wasCorrected());
        self::assertContains($this->id('beta'), $result->getElementIds());
        self::assertCount(1, $result->rules);
        self::assertFalse($result->rules[0]->matched);
    }

    public function testARuleWrittenForATypoGovernsWhileThatTypoStillFindsSomething(): void
    {
        // Nothing is corrected while the text finds results, so the rule for it governs as written.
        $this->rule(['matchValue' => self::TERM], [$this->hide('beta')]);

        $result = $this->search(self::TERM);

        self::assertFalse($result->wasCorrected());
        self::assertNotContains($this->id('beta'), $result->getElementIds());
    }

    public function testTheNextSearchSeesEveryChangeToARule(): void
    {
        $rule = $this->rule([], [$this->hide('beta')]);
        self::assertNotContains($this->id('beta'), $this->search(self::TERM)->getElementIds());

        $rule->enabled = false;
        self::assertTrue($this->plugin()->getRules()->saveRule($rule));
        self::assertContains($this->id('beta'), $this->search(self::TERM)->getElementIds());

        $rule->enabled = true;
        self::assertTrue($this->plugin()->getRules()->saveRule($rule));
        self::assertNotContains($this->id('beta'), $this->search(self::TERM)->getElementIds());

        $rule->setActions([$this->hide('gamma')]);
        self::assertTrue($this->plugin()->getRules()->saveRule($rule));
        $changed = $this->search(self::TERM);
        self::assertContains($this->id('beta'), $changed->getElementIds());
        self::assertNotContains($this->id('gamma'), $changed->getElementIds());

        $this->plugin()->getRules()->deleteRule($rule);
        self::assertContains($this->id('gamma'), $this->search(self::TERM)->getElementIds());
    }
    /**
     * A service that has not memoized anything, to prove a value really came back from the database.
     */
    private function freshRules(): Rules
    {
        return new Rules();
    }

    /**
     * @param array<string,mixed> $params
     */
    private function search(string $text, array $params = []): SearchResult
    {
        $params += ['limit' => 20];

        return $this->plugin()->getSearch()->search(SearchQuery::create($this->index->handle, $text, $params));
    }

    /**
     * The query word with one character dropped, which is what typo correction puts back.
     */
    private function typo(): string
    {
        return substr(self::TERM, 0, 5) . substr(self::TERM, 6);
    }

    private function id(string $word): int
    {
        return (int)$this->entries[$word]->id;
    }

    /**
     * @param array<string,mixed> $config
     * @param RuleAction[] $actions
     */
    private function rule(array $config = [], array $actions = []): SearchRule
    {
        $rule = new SearchRule($config + [
            'indexId' => $this->index->id,
            'name' => 'Test rule',
            'matchType' => RuleMatchType::Exact,
            'matchValue' => self::TERM,
        ]);

        $rule->setActions($actions);

        self::assertTrue(
            $this->plugin()->getRules()->saveRule($rule),
            implode(' ', $rule->getErrorSummary(true)),
        );

        $this->createdRules[] = $rule;

        return $rule;
    }

    private function hide(string|int $element): RuleAction
    {
        return $this->action(RuleActionType::Hide, $element);
    }

    private function promote(string|int $element): RuleAction
    {
        return $this->action(RuleActionType::Promote, $element);
    }

    private function pin(string|int $element, int $position): RuleAction
    {
        $action = $this->action(RuleActionType::Pin, $element);
        $action->position = $position;

        return $action;
    }

    private function boost(string|int $element, float $amount): RuleAction
    {
        $action = $this->action(RuleActionType::Boost, $element);
        $action->amount = $amount;

        return $action;
    }

    private function bury(string|int $element, float $amount): RuleAction
    {
        $action = $this->action(RuleActionType::Bury, $element);
        $action->amount = $amount;

        return $action;
    }

    private function action(RuleActionType $type, string|int $element): RuleAction
    {
        return new RuleAction([
            'type' => $type,
            'elementId' => is_string($element) ? $this->id($element) : $element,
            'elementType' => Entry::class,
        ]);
    }

    private function redirect(string $value): RuleAction
    {
        return new RuleAction(['type' => RuleActionType::Redirect, 'value' => $value]);
    }
}
