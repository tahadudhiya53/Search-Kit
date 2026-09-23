<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\db\Query;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\enums\RuleMatchType;
use Tahadudhiya\SearchKit\enums\SynonymType;
use Tahadudhiya\SearchKit\models\ResultExplanation;
use Tahadudhiya\SearchKit\models\RuleAction;
use Tahadudhiya\SearchKit\models\SearchDebug;
use Tahadudhiya\SearchKit\models\SearchExclusion;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\models\SearchRule;
use Tahadudhiya\SearchKit\models\Synonym;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * What the debugger reports about a real search over real content, so that what it explains is
 * what the pipeline actually did rather than a description of it.
 */
class SearchDebuggerTest extends ContentTestCase
{
    /** @var string A word no other content in the project can match. */
    private const TERM = 'zqxdiagnostic';

    private SearchIndex $index;

    /** @var array<string,Entry> Entries by the word that distinguishes them. */
    private array $entries = [];

    /** @var SearchRule[] */
    private array $createdRules = [];

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

        foreach (['alpha', 'beta', 'gamma'] as $word) {
            $this->entries[$word] = $this->createEntry(ucfirst($word) . ' ' . self::TERM);
        }

        $this->plugin()->getIndexing()->processPending($this->index);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRules as $rule) {
            $this->plugin()->getRules()->deleteRule($rule);
        }

        foreach ($this->createdSynonyms as $synonym) {
            $this->plugin()->getSynonyms()->deleteSynonym($synonym);
        }

        $this->createdRules = [];
        $this->createdSynonyms = [];
        $this->entries = [];

        parent::tearDown();
    }

    public function testTheQueryIsReportedAsThePipelineReadIt(): void
    {
        $debug = $this->debug('The ' . strtoupper(self::TERM));

        self::assertSame('The ' . strtoupper(self::TERM), $debug->originalRaw);
        self::assertSame('the ' . self::TERM, $debug->originalNormalized);
        self::assertSame(['the'], $debug->removedStopWords);
        self::assertSame(self::TERM, $debug->effectiveNormalized);
        self::assertSame([self::TERM], array_column($debug->terms, 'text'));
    }

    public function testTheProviderAndTheRequestItWasGivenAreReported(): void
    {
        $debug = $this->debug(self::TERM);

        self::assertSame(CraftProvider::class, $debug->provider);
        self::assertSame('Craft', $debug->providerName);
        self::assertContains('search', $debug->capabilities);

        // Craft scores results itself, so the debugger must not claim weights decided the ranking.
        self::assertNotContains('fieldWeighting', $debug->capabilities);
        self::assertFalse($debug->weightsApplied);
        self::assertSame([Entry::class => ['title' => 5]], $debug->fieldWeights);

        self::assertCount(1, $debug->executions);
        self::assertSame(SearchDebug::PURPOSE_SEARCH, $debug->executions[0]['purpose']);
        self::assertSame(3, $debug->executions[0]['total']);

        // The request is the query in the provider's own syntax, which is what it really ran: the
        // index matches prefixes, so the term carries that and so does what Craft was asked.
        self::assertSame('prefix', $debug->terms[0]['partial']);
        self::assertSame(self::TERM . '*', $debug->executions[0]['request']['craftQuery']);
        self::assertSame([Entry::class], $debug->executions[0]['request']['elementTypes']);

        // Only what the provider declared safe to show, never everything it reported.
        self::assertSame(
            ['craftQuery', 'elementTypes', 'orderBy'],
            array_keys($debug->executions[0]['request']),
        );
    }

    public function testEveryStageOfTheSearchIsTimed(): void
    {
        $debug = $this->debug(self::TERM);

        foreach (['setup', 'parse', 'rules', 'provider', 'elements', 'highlighting'] as $stage) {
            self::assertArrayHasKey($stage, $debug->timings);
            self::assertGreaterThanOrEqual(0.0, $debug->timings[$stage]);
        }

        self::assertGreaterThan(0.0, $debug->getTotalTime());
    }

    public function testEachResultIsExplainedByWhatItMatchedAndWhatScoredIt(): void
    {
        $debug = $this->debug(self::TERM);

        self::assertCount(3, $debug->results);

        foreach ($debug->results as $position => $explanation) {
            self::assertSame($position + 1, $explanation->position);
            self::assertSame(Entry::class, $explanation->elementType);
            self::assertSame($this->sectionSiteId(), $explanation->siteId);

            // Matched fields are read from the index's own values, with the weight it gives them.
            self::assertSame(['title' => 5], $explanation->matchedFields);
            self::assertSame(ResultExplanation::BY_SCORE, $explanation->rankedBy);
            self::assertTrue($explanation->hasProviderScore());
            self::assertGreaterThan(0.0, $explanation->score);
            self::assertSame($explanation->score, $explanation->finalScore);
        }
    }

    public function testABoostIsExplainedByTheScoreItActuallyMoved(): void
    {
        $this->rule([$this->action(RuleActionType::Boost, 'gamma', ['amount' => 500.0])]);

        $debug = $this->debug(self::TERM);
        $boosted = $this->explanationFor($debug, (int)$this->entries['gamma']->id);

        self::assertSame(1, $boosted->position);
        self::assertSame(ResultExplanation::BY_SCORE, $boosted->rankedBy);
        self::assertSame(500.0, $boosted->scoreAdjustment);
        self::assertSame($boosted->score + 500.0, $boosted->finalScore);
        self::assertSame('adjustment', $boosted->ruleEffects[0]['action']);
        self::assertSame(500.0, $boosted->ruleEffects[0]['amount']);

        // A result nothing moved keeps the provider's own score, with nothing added to it.
        $untouched = $this->explanationFor($debug, (int)$this->entries['alpha']->id);
        self::assertSame(0.0, $untouched->scoreAdjustment);
    }

    public function testAPinIsExplainedByWhereTheRulePutIt(): void
    {
        $this->rule([$this->action(RuleActionType::Pin, 'gamma', ['position' => 1])]);

        $debug = $this->debug(self::TERM);
        $pinned = $this->explanationFor($debug, (int)$this->entries['gamma']->id);

        self::assertSame(1, $pinned->position);
        self::assertTrue($pinned->pinned);
        self::assertSame(RuleActionType::Pin->value, $pinned->ruleEffects[0]['action']);

        // A pin decided where it sits, so nothing may present its position as a ranking.
        self::assertSame(ResultExplanation::BY_PIN, $pinned->rankedBy);
        self::assertSame(1, $pinned->pinnedPosition);
        self::assertFalse($pinned->hasProviderScore());
        self::assertSame(0.0, $pinned->score);
    }

    public function testAPromotionIsExplainedAsAPlacementRatherThanAScore(): void
    {
        $featured = $this->createEntry('Entirely unrelated feature');
        $this->plugin()->getIndexing()->processPending($this->index);

        $this->rule([new RuleAction([
            'type' => RuleActionType::Promote,
            'elementId' => (int)$featured->id,
            'elementType' => Entry::class,
        ])]);

        $debug = $this->debug(self::TERM);
        $promoted = $this->explanationFor($debug, (int)$featured->id);

        self::assertSame(1, $promoted->position);
        self::assertTrue($promoted->promoted);
        self::assertSame(ResultExplanation::BY_PROMOTION, $promoted->rankedBy);
        self::assertNull($promoted->pinnedPosition);

        // The search never matched it, so there is no score of its own to show.
        self::assertFalse($promoted->hasProviderScore());
        self::assertSame(0.0, $promoted->score);
    }

    public function testSynonymsAreReportedAsAlternativesTheTermAlsoAccepts(): void
    {
        $synonym = new Synonym([
            'indexId' => $this->index->id,
            'type' => SynonymType::TwoWay,
            'terms' => [self::TERM, 'zqxequivalent'],
        ]);

        self::assertTrue(
            $this->plugin()->getSynonyms()->saveSynonym($synonym),
            implode(' ', $synonym->getErrorSummary(true)),
        );
        $this->createdSynonyms[] = $synonym;

        $debug = $this->debug(self::TERM);

        self::assertSame(['zqxequivalent'], $debug->terms[0]['alternatives']);
    }

    public function testAHiddenResultIsNamedAlongsideTheRuleThatHidIt(): void
    {
        $rule = $this->rule([$this->action(RuleActionType::Hide, 'beta')]);

        $debug = $this->debug(self::TERM);

        self::assertCount(2, $debug->results);
        self::assertCount(1, $debug->exclusions);

        $excluded = $debug->exclusions[0];
        self::assertSame((int)$this->entries['beta']->id, $excluded->elementId);
        self::assertSame(SearchExclusion::HIDDEN_BY_RULE, $excluded->reason);

        // It was kept out before the search ran, so it is reported as an exclusion SearchKit made
        // rather than as a result this query matched.
        self::assertNotContains((int)$this->entries['beta']->id, array_map(
            static fn(ResultExplanation $explanation) => $explanation->elementId,
            $debug->results,
        ));
        self::assertSame((int)$rule->id, $excluded->ruleId);

        // Read back from Craft, so what was taken out can be recognized rather than guessed at.
        self::assertSame($this->entries['beta']->title, $excluded->title);
        self::assertSame('live', $excluded->status);
    }

    public function testEveryRuleConsideredIsReportedWithWhyItDidOrDidNotMatch(): void
    {
        $this->rule([$this->action(RuleActionType::Hide, 'beta')], ['enabled' => false]);
        $this->rule([$this->action(RuleActionType::Boost, 'alpha', ['amount' => 1.0])], ['matchValue' => 'something else']);

        $result = $this->debugResult(self::TERM);
        $debug = $result->debug;

        self::assertNotNull($debug);
        self::assertCount(2, $result->rules);
        self::assertSame(2, $debug->plan['rulesConsidered']);
        self::assertSame(0, $debug->plan['rulesMatched']);

        $reasons = array_column(array_map(static fn($evaluation) => $evaluation->toArray(), $result->rules), 'reason');
        self::assertContains('disabled', $reasons);
        self::assertContains('noMatch', $reasons);

        // Nothing matched, so every result keeps exactly what the provider gave it.
        self::assertCount(3, $debug->results);
        self::assertSame([], $debug->exclusions);
    }

    public function testATypoCorrectionIsReportedWithTheSearchItRanAgain(): void
    {
        $typo = substr(self::TERM, 0, 5) . substr(self::TERM, 6);

        $debug = $this->debug($typo);

        // What was typed stands beside what was searched for instead, never replaced by it.
        self::assertSame($typo, $debug->originalRaw);
        self::assertSame($typo, $debug->originalNormalized);
        self::assertSame(self::TERM, $debug->correctedTo);
        self::assertSame(self::TERM, $debug->effectiveNormalized);
        self::assertSame([self::TERM], array_column($debug->terms, 'text'));
        self::assertTrue($debug->terms[0]['corrected']);
        self::assertCount(2, $debug->executions);
        self::assertSame(SearchDebug::PURPOSE_SEARCH, $debug->executions[0]['purpose']);
        self::assertSame(0, $debug->executions[0]['total']);
        self::assertSame(SearchDebug::PURPOSE_CORRECTION, $debug->executions[1]['purpose']);
        self::assertSame(3, $debug->executions[1]['total']);
    }

    public function testADebuggedSearchIsNotRecordedAsSearchActivity(): void
    {
        $before = $this->recordedSearches();

        $this->debug(self::TERM);

        // A search run to diagnose one is not something anybody searched for.
        self::assertSame($before, $this->recordedSearches());
    }

    private function debug(string $text): SearchDebug
    {
        $debug = $this->debugResult($text)->debug;
        self::assertNotNull($debug);

        return $debug;
    }

    private function debugResult(string $text): SearchResult
    {
        return $this->plugin()->getDebugger()->run(
            SearchQuery::create($this->index->handle, $text, ['limit' => 20]),
        );
    }

    private function explanationFor(SearchDebug $debug, int $elementId): ResultExplanation
    {
        foreach ($debug->results as $explanation) {
            if ($explanation->elementId === $elementId) {
                return $explanation;
            }
        }

        self::fail("Element {$elementId} was not among the explained results.");
    }

    private function recordedSearches(): int
    {
        return (int)(new Query())
            ->from([Table::SEARCHEVENTS])
            ->where(['indexId' => $this->index->id])
            ->count();
    }

    /**
     * @param RuleAction[] $actions
     * @param array<string,mixed> $config
     */
    private function rule(array $actions, array $config = []): SearchRule
    {
        $rule = new SearchRule($config + [
            'indexId' => $this->index->id,
            'name' => 'Debugger test rule',
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

    /**
     * @param array<string,mixed> $config
     */
    private function action(RuleActionType $type, string $word, array $config = []): RuleAction
    {
        return new RuleAction($config + [
            'type' => $type,
            'elementId' => (int)$this->entries[$word]->id,
            'elementType' => Entry::class,
        ]);
    }
}
