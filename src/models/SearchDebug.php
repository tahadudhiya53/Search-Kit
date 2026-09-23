<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use Tahadudhiya\SearchKit\base\SearchProviderInterface;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\enums\RuleActionType;

/**
 * What one search actually did, recorded as it happened. Nothing here is worked out afterwards:
 * every value is written by the step that produced it, so an explanation can never describe a
 * search other than the one that ran.
 */
class SearchDebug extends Model
{
    /** @var string The search as it was asked for. */
    public const PURPOSE_SEARCH = 'search';

    /** @var string The same search retried against a correction of the query. */
    public const PURPOSE_CORRECTION = 'correction';

    /** @var string Run again because a correction brought different rules with it. */
    public const PURPOSE_REPLAN = 'replan';

    /** @var string Results a boost or a bury moves, asked for by name. */
    public const PURPOSE_ADJUSTED = 'adjusted';

    /** @var string The query exactly as it was typed, whatever was searched for in the end. */
    public string $originalRaw = '';

    /** @var string That same text normalized, before any term was dropped or corrected. */
    public string $originalNormalized = '';

    /** @var string What the provider was actually searched with, written back out from its terms. */
    public string $effectiveNormalized = '';

    /** @var bool Whether this index reads search operators out of the query text. */
    public bool $operators = false;

    /** @var array<int,array<string,mixed>> Every parsed term the provider was given, and what it means. */
    public array $terms = [];

    /** @var string[] Words dropped as too common to narrow anything down. */
    public array $removedStopWords = [];

    /** @var string|null What a typo correction searched for instead of what was typed. */
    public ?string $correctedTo = null;

    public string $indexHandle = '';
    public string $indexName = '';

    /** @var int|null The one site this search covered, or null when it covered more than one. */
    public ?int $siteId = null;

    /** @var int[]|null Every site it covered, or null for every site there is. */
    public ?array $siteIds = null;

    /** @var string[] The element types the index searches. */
    public array $elementTypes = [];

    /** @var string[] The languages the query text was read in — one per language the sites use. */
    public array $languages = [];

    /**
     * @var string[] Expansions a synonym group offered for some of the sites being searched but not
     * all of them, so applying them would have reached beyond the site they were written for.
     */
    public array $withheldSynonyms = [];

    /** @var string The provider class that served the search. Never its settings. */
    public string $provider = '';

    public string $providerName = '';

    /** @var string[] What the provider declared it can do. */
    public array $capabilities = [];

    /** @var array<string,array<string,int>> Element type => field handle => configured weight. */
    public array $fieldWeights = [];

    /** @var bool Whether the provider ranks by those weights, or scores results its own way. */
    public bool $weightsApplied = false;

    /**
     * @var array<int,array<string,mixed>> Every call made to the provider: why it was made, the
     * window it asked for, what came back, how long it took, and the request where the provider
     * reported one.
     */
    public array $executions = [];

    /** @var array<string,float> Milliseconds spent in each stage of the pipeline. */
    public array $timings = [];

    /** @var array<string,mixed> How the rules decided this page was read. */
    public array $plan = [];

    /** @var ResultExplanation[] */
    public array $results = [];

    /** @var SearchExclusion[] What SearchKit itself kept out of this search, and why. */
    public array $exclusions = [];

    /** @var float When the last stage ended, as a high-resolution reading. */
    private float $_cursor = 0.0;

    public function init(): void
    {
        parent::init();

        $this->_cursor = (float)hrtime(true);
    }

    /**
     * Closes off a stage of the pipeline and starts the next. A stage entered more than once, as a
     * retried search is, accumulates rather than losing what came before.
     */
    public function mark(string $stage): void
    {
        $now = (float)hrtime(true);
        $this->timings[$stage] = ($this->timings[$stage] ?? 0.0) + ($now - $this->_cursor) / 1_000_000;
        $this->_cursor = $now;
    }

    public function getTotalTime(): float
    {
        return array_sum($this->timings);
    }

    /**
     * @param int[]|null $siteIds The sites the search covered, or null for every site there is.
     */
    public function readIndex(SearchIndex $index, ?array $siteIds): void
    {
        $this->indexHandle = $index->handle;
        $this->indexName = (string)$index;
        $this->siteIds = $siteIds;
        $this->siteId = $siteIds !== null && count($siteIds) === 1 ? $siteIds[0] : null;
        $this->elementTypes = $index->getElementTypes();

        foreach ($this->elementTypes as $elementType) {
            $this->fieldWeights[$elementType] = $index->getFieldWeights($elementType);
        }
    }

    /**
     * Provider settings are deliberately absent: they are where credentials live.
     */
    public function readProvider(SearchProviderInterface $provider): void
    {
        $this->provider = $provider::class;
        $this->providerName = $provider::displayName();
        $this->capabilities = array_map(
            static fn(ProviderCapability $capability) => $capability->value,
            $provider::capabilities(),
        );
        $this->weightsApplied = $provider->supports(ProviderCapability::FieldWeighting);
    }

    /**
     * What was typed, kept before anything can search for something else instead.
     */
    public function readOriginalQuery(ParsedQuery $parsed, bool $operators): void
    {
        $this->originalRaw = $parsed->raw;
        $this->originalNormalized = $parsed->normalized;
        $this->operators = $operators;
        $this->removedStopWords = $parsed->removedStopWords;
        $this->languages = $parsed->languages;
        $this->withheldSynonyms = $parsed->withheldSynonyms;
        $this->readEffectiveQuery($parsed);
    }

    /**
     * The terms the provider was actually given, which after a correction are the corrected ones.
     * Recorded separately from what was typed, so one can never stand in for the other.
     */
    public function readEffectiveQuery(ParsedQuery $parsed): void
    {
        $this->effectiveNormalized = $parsed->getText();
        $this->terms = array_map(
            static fn(QueryTerm $term) => [
                'text' => $term->text,
                'original' => $term->original,
                'excluded' => $term->excluded,
                'phrase' => $term->phrase,
                'exact' => $term->exact,
                'corrected' => $term->corrected,
                'wildcard' => $term->wildcard,
                'partial' => $term->partial->value,
                'alternatives' => array_map(static fn(QueryTerm $alternative) => $alternative->text, $term->getAlternatives()),
            ],
            $parsed->getTerms(),
        );
    }

    /**
     * One call to the provider, recorded where it was made.
     *
     * @param array<string,mixed> $diagnostics What the provider declared safe to show about it.
     */
    public function recordExecution(
        string $purpose,
        SearchQuery $query,
        SearchResult $result,
        float $milliseconds,
        array $diagnostics,
    ): void {
        $this->executions[] = [
            'purpose' => $purpose,
            'offset' => $query->offset,
            'limit' => $query->limit,
            'excluded' => count($query->getExcludedElements()),
            'returned' => count($result->hits),
            'total' => $result->total,
            'time' => $milliseconds,
            // Only what the provider declared safe to show. Nothing it reported reaches a page
            // unless it said so, so a setting or a credential cannot leak through here.
            'request' => $diagnostics,
        ];
    }

    /**
     * A result the search did return but could not show, recorded where it was dropped.
     */
    public function recordDropped(SearchHit $hit): void
    {
        $this->exclusions[] = new SearchExclusion([
            'elementId' => $hit->elementId,
            'siteId' => $hit->siteId,
            'elementType' => $hit->elementType,
            'reason' => SearchExclusion::NOT_AVAILABLE,
            'ruleId' => $this->placingRule($hit),
        ]);
    }

    /**
     * Reads the finished page and the plan that shaped it into an explanation per result. Both are
     * the objects the search itself used, so this describes what happened rather than guessing it.
     */
    public function explain(SearchResult $result, RulePlan $plan, SearchIndex $index): void
    {
        $this->correctedTo = $result->correctedText;
        $this->plan = [
            'windowOffset' => $plan->windowOffset,
            'windowLimit' => $plan->windowLimit,
            'assembles' => $plan->assembles,
            'reordered' => $plan->reordered,
            'reorderSkipped' => $plan->reorderSkipped,
            'refetchesAdjusted' => $plan->refetchesAdjusted,
            'redirect' => $plan->redirect,
            'redirectRuleId' => $plan->redirectRuleId,
            'rulesConsidered' => count($plan->evaluations),
            'rulesMatched' => count($plan->getMatchedRules()),
        ];

        foreach ($result->hits as $rank => $hit) {
            $this->results[] = $this->explainHit($hit, $result->offset + $rank + 1, $index);
        }

        // A rule's target is named whether or not this query would have matched it: what is
        // recorded is that SearchKit kept it out, not that the provider would have returned it.
        foreach ($plan->hidden as $hidden) {
            $this->exclusions[] = new SearchExclusion([
                'elementId' => $hidden['elementId'],
                'siteId' => $hidden['siteId'],
                'elementType' => $hidden['elementType'],
                'reason' => SearchExclusion::HIDDEN_BY_RULE,
                'ruleId' => $hidden['ruleId'],
            ]);
        }
    }

    private function explainHit(SearchHit $hit, int $position, SearchIndex $index): ResultExplanation
    {
        $weights = $hit->elementType !== null ? $index->getFieldWeights($hit->elementType) : [];
        $matched = [];

        foreach ($hit->matchedFields as $handle) {
            $matched[$handle] = $weights[$handle] ?? null;
        }

        return new ResultExplanation([
            'position' => $position,
            'rankedBy' => $this->rankedBy($hit),
            'pinnedPosition' => $hit->pinned ? $this->pinnedPosition($hit) : null,
            'elementId' => $hit->elementId,
            'siteId' => $hit->siteId,
            'elementType' => $hit->elementType,
            'title' => $hit->element?->getUiLabel(),
            'matchedFields' => $matched,
            'score' => $hit->score,
            'scoreAdjustment' => $hit->scoreAdjustment,
            'finalScore' => $hit->getFinalScore(),
            'pinned' => $hit->pinned,
            'promoted' => $hit->promoted,
            'ruleEffects' => $hit->ruleEffects,
        ]);
    }

    /**
     * What decided where this result sits. A placed result was put there by a rule and never
     * scored by the provider, so its position is not a ranking and must not read as one.
     */
    private function rankedBy(SearchHit $hit): string
    {
        return match (true) {
            $hit->pinned => ResultExplanation::BY_PIN,
            $hit->promoted => ResultExplanation::BY_PROMOTION,
            default => ResultExplanation::BY_SCORE,
        };
    }

    /**
     * The position a pin asked for, which is what put the result where it is.
     */
    private function pinnedPosition(SearchHit $hit): ?int
    {
        foreach ($hit->ruleEffects as $effect) {
            if (($effect['action'] ?? null) === RuleActionType::Pin->value && isset($effect['position'])) {
                return (int)$effect['position'];
            }
        }

        return null;
    }

    /**
     * The rule that put a result in the list, for one that a rule placed.
     */
    private function placingRule(SearchHit $hit): ?int
    {
        foreach ($hit->ruleEffects as $effect) {
            if (isset($effect['ruleId'])) {
                return (int)$effect['ruleId'];
            }
        }

        return null;
    }
}
