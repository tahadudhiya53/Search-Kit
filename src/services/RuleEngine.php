<?php

namespace Tahadudhiya\SearchKit\services;

use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\models\RuleAction;
use Tahadudhiya\SearchKit\models\RuleEvaluation;
use Tahadudhiya\SearchKit\models\RulePlan;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\models\SearchRule;
use Tahadudhiya\SearchKit\SearchKit;
use yii\base\Component;

/**
 * Turns the rules governing a query into changes to its results. Nothing here knows what served the
 * search: a rule acts on normalized hits, so it works the same whatever the provider is.
 */
class RuleEngine extends Component
{
    /** @var RuleActionType[] Actions that decide a result's fate on their own; only one of them may. */
    private const EXCLUSIVE = [RuleActionType::Hide, RuleActionType::Pin, RuleActionType::Promote];

    /**
     * @var int How far into the results a boost or a bury can still be worked out. Reordering needs
     * every result above the page in hand, so past this point the page is read straight instead.
     */
    public const REORDER_LIMIT = SearchQuery::MAX_LIMIT;

    private ?Rules $_rules = null;
    private ?Normalization $_normalization = null;

    /**
     * Settles every conflict between the rules governing this query, before the search runs. The
     * plan decides which results the provider must leave out and how the page is read, so it cannot
     * wait until afterwards.
     */
    public function plan(SearchQuery $query, SearchIndex $index, ?string $text = null): RulePlan
    {
        $plan = new RulePlan();

        if ($index->id === null) {
            $this->decideWindow($plan, $query);

            return $plan;
        }

        $normalized = $this->getNormalization()->normalize($text ?? $query->text);

        // The sites this search covers, or null for every site there is.
        $scope = $query->getSiteScope($index->siteId);
        $matched = [];

        foreach ($this->getRules()->getRulesForIndex((int)$index->id, $query->siteId) as $rule) {
            $evaluation = $this->evaluate($rule, $normalized);
            $plan->evaluations[] = $evaluation;

            if ($evaluation->matched) {
                $matched[] = [$rule, $evaluation];
            }
        }

        // Two passes, because what happens to a result is decided before how far it moves. Rules
        // arrive highest priority first, so the first claim on a result is the one that stands, and
        // an adjustment then applies only to a result no rule claimed.
        foreach ($matched as [$rule, $evaluation]) {
            $this->resolveExclusive($rule, $evaluation, $plan, $index, $scope);
        }

        foreach ($matched as [$rule, $evaluation]) {
            $this->resolveAdjustments($rule, $evaluation, $plan, $index, $scope);
        }

        $this->decideWindow($plan, $query);
        $this->recordSkippedAdjustments($plan);

        return $plan;
    }

    /**
     * The query the provider is asked to run: the results the rules removed or place themselves are
     * left out of it, and the window is whatever the rules need to arrange the page.
     */
    public function windowQuery(SearchQuery $query, RulePlan $plan): SearchQuery
    {
        if (!$plan->affectsSearch()) {
            return $query;
        }

        $window = clone $query;
        $window->offset = (int)$plan->windowOffset;
        $window->limit = (int)$plan->windowLimit;

        foreach ($plan->excludedElements() as $excluded) {
            $window->excludeElement($excluded['elementId'], $excluded['siteId']);
        }

        return $window;
    }

    /**
     * Whether a window already read covers everything this plan needs, so the search does not have
     * to be run again after the query it was planned for changed.
     */
    public function windowCovers(RulePlan $plan, SearchQuery $execution, SearchQuery $planned): bool
    {
        if ($this->exclusionKeys($execution) !== $this->exclusionKeys($planned)) {
            return false;
        }

        // A window read from the start covers any shorter one read from the start.
        if ($plan->windowOffset === 0 && $execution->offset === 0) {
            return $execution->limit >= (int)$plan->windowLimit;
        }

        return $execution->offset === $plan->windowOffset && $execution->limit === $plan->windowLimit;
    }

    /**
     * @return string[] The results a query leaves out, in a fixed order so two can be compared.
     */
    private function exclusionKeys(SearchQuery $query): array
    {
        $keys = array_map(
            static fn(array $excluded) => RulePlan::key($excluded['elementId'], $excluded['siteId']),
            $query->getExcludedElements(),
        );

        sort($keys);

        return $keys;
    }

    /**
     * Applies the plan to what the provider returned, and cuts it back to the page that was asked
     * for. Hits carry only identifiers here, so nothing in this class loads an element.
     *
     * @param SearchHit[] $adjusted The results a boost or a bury moves, fetched in their own right.
     */
    public function apply(
        SearchResult $result,
        RulePlan $plan,
        SearchQuery $query,
        SearchQuery $execution,
        SearchIndex $index,
        array $adjusted = [],
    ): void {
        $result->rules = $plan->evaluations;
        $result->redirect = $plan->redirect;

        // Hidden results never arrived: the provider was asked to leave them out, so the total it
        // reported already describes the final result set.
        if (!$plan->rearrangesResults()) {
            $this->cut($result, $query, $execution, $plan);

            return;
        }

        $placed = $this->placements($plan, $index);
        $plan->placedHits = [...array_values($placed['pins']), ...$placed['promoted']];
        $result->total += count($placed['pins']) + count($placed['promoted']);

        if (!$plan->assembles) {
            // Every placed result sits above this page, so what came back is already the page.
            $hits = array_slice($result->hits, 0, $query->limit);
            $this->adjust($hits, $plan);
            $result->hits = $hits;

            return;
        }

        // Held back from the ranked list, so they are put back and counted here. One that the
        // search did not match was never returned, so a boost adds nothing that was not found.
        $hits = [...$result->hits, ...$adjusted];
        $result->total += count($adjusted);

        $this->adjust($hits, $plan);

        if ($plan->reordered) {
            $this->reorder($hits);
        }

        $result->hits = array_slice(
            $this->insert($hits, $placed),
            $query->offset - $execution->offset,
            $query->limit,
        );
    }

    /**
     * Settles the explanation against what the viewer was actually allowed to see. A placed result
     * that could not be loaded was never shown, so the rule did not place anything — and its
     * identifier is taken out of the explanation rather than naming content to someone who may not
     * see it.
     */
    public function reconcilePlacements(RulePlan $plan): void
    {
        $withheld = [];

        foreach ($plan->placedHits as $hit) {
            if ($hit->element === null) {
                // A result is the element *in a site*: the same element elsewhere is a different
                // result, and may well have been shown.
                $withheld[RulePlan::key($hit->elementId, $hit->siteId)] = true;
            }
        }

        if ($withheld === []) {
            return;
        }

        foreach ($plan->evaluations as $evaluation) {
            foreach ($evaluation->effects as $key => $effect) {
                $action = $effect['action'] ?? null;
                $elementId = $effect['elementId'] ?? null;
                $siteId = $effect['siteId'] ?? null;

                if ($elementId === null || !isset($withheld[RulePlan::key($elementId, $siteId)])) {
                    continue;
                }

                if ($action !== RuleActionType::Pin->value && $action !== RuleActionType::Promote->value) {
                    continue;
                }

                $evaluation->effects[$key] = [
                    'action' => $action,
                    'elementId' => null,
                    'siteId' => null,
                    'position' => $effect['position'] ?? null,
                    'applied' => false,
                    'outcome' => RulePlan::NOT_VIEWABLE,
                ];
            }
        }
    }

    /**
     * Whether a rule governs this query, and when it does not, why.
     */
    private function evaluate(SearchRule $rule, string $text): RuleEvaluation
    {
        if (!$rule->enabled) {
            return RuleEvaluation::for($rule, false, RuleEvaluation::DISABLED);
        }

        if (!$rule->isInForce()) {
            return RuleEvaluation::for($rule, false, RuleEvaluation::OUT_OF_SCHEDULE);
        }

        if (!$rule->matches($text)) {
            return RuleEvaluation::for($rule, false, RuleEvaluation::NO_MATCH);
        }

        return RuleEvaluation::for($rule, true, RuleEvaluation::MATCHED);
    }

    /**
     * Hides, pins, promotions and redirects: the actions that decide a result's fate outright. The
     * first rule to claim a result keeps it, whatever any later rule asks for.
     */
    private function resolveExclusive(
        SearchRule $rule,
        RuleEvaluation $evaluation,
        RulePlan $plan,
        SearchIndex $index,
        ?array $scope,
    ): void {
        $ruleId = (int)$rule->id;

        foreach ($rule->getActions() as $action) {
            if ($action->type === RuleActionType::Redirect) {
                $this->claimRedirect($action, $rule, $evaluation, $plan, $scope);
                continue;
            }

            if (!in_array($action->type, self::EXCLUSIVE, true)) {
                continue;
            }

            $target = $this->unclaimedTarget($action, $rule, $index, $evaluation, $plan, $scope);

            if ($target === null) {
                continue;
            }

            match ($action->type) {
                RuleActionType::Pin => $this->claimPin($action, $target, $ruleId, $evaluation, $plan),
                RuleActionType::Promote => $this->claimPromotion($target, $ruleId, $evaluation, $plan, $action),
                default => $this->claimHide($target, $ruleId, $evaluation, $plan, $action),
            };
        }
    }

    /**
     * Boosts and buries. They accumulate, and addition settles the same way whatever order it
     * happens in, so two rules moving one result never disagree.
     */
    private function resolveAdjustments(
        SearchRule $rule,
        RuleEvaluation $evaluation,
        RulePlan $plan,
        SearchIndex $index,
        ?array $scope,
    ): void {
        foreach ($rule->getActions() as $action) {
            if (!$action->type->adjustsScore()) {
                continue;
            }

            $target = $this->unclaimedTarget($action, $rule, $index, $evaluation, $plan, $scope);

            if ($target === null) {
                continue;
            }

            $key = RulePlan::key($target['elementId'], $target['siteId']);
            $entry = $plan->adjustments[$key] ?? [
                'elementId' => $target['elementId'],
                'siteId' => $target['siteId'],
                'amount' => 0.0,
                'ruleIds' => [],
            ];

            $entry['amount'] += $action->adjustment();
            $entry['ruleIds'][] = (int)$rule->id;
            $plan->adjustments[$key] = $entry;

            $this->record($evaluation, $action, true, 'applied');
        }
    }

    /**
     * The result an action acts on, or null when it names none in scope or an earlier rule already
     * claimed it. The first claim on a result stands — a hidden, pinned or promoted result is no
     * longer ranked by its score, so nothing later may move it either.
     *
     * @param int[]|null $scope The sites this search covers, or null for every site there is.
     * @return array{elementId:int,elementType:string,siteId:int|null}|null
     */
    private function unclaimedTarget(
        RuleAction $action,
        SearchRule $rule,
        SearchIndex $index,
        RuleEvaluation $evaluation,
        RulePlan $plan,
        ?array $scope,
    ): ?array {
        $target = $this->target($action, $rule, $index, $evaluation, $scope);

        if ($target === null) {
            return null;
        }

        $claim = $plan->claimedBy($target['elementId'], $target['siteId']);

        if ($claim !== null) {
            $this->record($evaluation, $action, false, "claimedBy:{$claim['action']}:{$claim['ruleId']}");

            return null;
        }

        return $target;
    }

    /**
     * The result an action acts on, with the site it acts in settled. An action outside the site
     * scope the rule was read for is refused rather than quietly reaching another site.
     *
     * @param int[]|null $scope The sites this search covers, or null for every site there is.
     * @return array{elementId:int,elementType:string,siteId:int|null}|null
     */
    private function target(
        RuleAction $action,
        SearchRule $rule,
        SearchIndex $index,
        RuleEvaluation $evaluation,
        ?array $scope,
    ): ?array {
        if ($action->elementId === null || $action->elementType === null) {
            $this->record($evaluation, $action, false, 'noTarget');

            return null;
        }

        // Checked again here, not only when the rule was saved: the index's configuration may have
        // stopped covering this kind of result since.
        if (!in_array($action->elementType, $index->getElementTypes(), true)) {
            $this->record($evaluation, $action, false, 'elementTypeNotSearchedByIndex');

            return null;
        }

        $siteId = $action->siteId ?? $rule->scopeSiteId($index->siteId);

        if ($siteId !== null && !$index->coversSite($siteId)) {
            $this->record($evaluation, $action, false, 'siteOutsideIndexScope');

            return null;
        }

        if ($action->type->inserts() && $siteId === null) {
            $this->record($evaluation, $action, false, 'noSiteToPlaceIn');

            return null;
        }

        // Nothing may act outside the sites this search covers, whatever the rule asked for.
        if ($siteId !== null && $scope !== null && !in_array($siteId, $scope, true)) {
            $this->record($evaluation, $action, false, 'siteOutsideSearchScope');

            return null;
        }

        return [
            'elementId' => $action->elementId,
            'elementType' => $action->elementType,
            'siteId' => $siteId,
        ];
    }

    /**
     * A redirect sends the whole search somewhere, so it may only come from a rule whose site covers
     * the whole search. A rule written for one site does not redirect a search across every site:
     * which site's answer that would be is not something to guess at.
     */
    private function claimRedirect(
        RuleAction $action,
        SearchRule $rule,
        RuleEvaluation $evaluation,
        RulePlan $plan,
        ?array $scope,
    ): void {
        // A rule written for one site only redirects a search of that site and nothing else.
        if ($rule->siteId !== null && $scope !== [$rule->siteId]) {
            $this->record($evaluation, $action, false, 'redirectNarrowerThanSearch');

            return;
        }

        if ($plan->redirect !== null) {
            $this->record($evaluation, $action, false, "claimedBy:redirect:{$plan->redirectRuleId}");

            return;
        }

        $plan->redirect = $action->value;
        $plan->redirectRuleId = (int)$rule->id;
        $this->record($evaluation, $action, true, 'applied');
    }

    /**
     * @param array{elementId:int,elementType:string,siteId:int|null} $target
     */
    private function claimHide(
        array $target,
        int $ruleId,
        RuleEvaluation $evaluation,
        RulePlan $plan,
        RuleAction $action,
    ): void {
        $key = RulePlan::key($target['elementId'], $target['siteId']);

        $plan->hidden[$key] = [
            'elementId' => $target['elementId'],
            'elementType' => $target['elementType'],
            'siteId' => $target['siteId'],
            'ruleId' => $ruleId,
        ];

        $plan->claim($target['elementId'], $target['siteId'], $ruleId, RuleActionType::Hide->value);
        $this->record($evaluation, $action, true, 'applied');
    }

    /**
     * @param array{elementId:int,elementType:string,siteId:int|null} $target
     */
    private function claimPin(
        RuleAction $action,
        array $target,
        int $ruleId,
        RuleEvaluation $evaluation,
        RulePlan $plan,
    ): void {
        $position = (int)$action->position;

        if (isset($plan->pins[$position])) {
            $this->record($evaluation, $action, false, "positionTakenBy:{$plan->pins[$position]['ruleId']}");

            return;
        }

        $plan->pins[$position] = [
            'elementId' => $target['elementId'],
            'elementType' => $target['elementType'],
            'siteId' => (int)$target['siteId'],
            'ruleId' => $ruleId,
        ];

        $plan->claim($target['elementId'], $target['siteId'], $ruleId, RuleActionType::Pin->value);
        $this->record($evaluation, $action, true, 'applied');
    }

    /**
     * @param array{elementId:int,elementType:string,siteId:int|null} $target
     */
    private function claimPromotion(
        array $target,
        int $ruleId,
        RuleEvaluation $evaluation,
        RulePlan $plan,
        RuleAction $action,
    ): void {
        $key = RulePlan::key($target['elementId'], $target['siteId']);

        $plan->promoted[$key] = [
            'elementId' => $target['elementId'],
            'elementType' => $target['elementType'],
            'siteId' => (int)$target['siteId'],
            'ruleId' => $ruleId,
        ];

        $plan->claim($target['elementId'], $target['siteId'], $ruleId, RuleActionType::Promote->value);
        $this->record($evaluation, $action, true, 'applied');
    }

    /**
     * How the page is read. Placed results only ever reach so far down the list, so a page past that
     * is the organic list shifted down and can be read straight; anything nearer the top, or any
     * reordering, needs the head of the list assembled.
     */
    private function decideWindow(RulePlan $plan, SearchQuery $query): void
    {
        $plan->reordered = false;

        if (!$plan->rearrangesResults()) {
            $plan->assembles = true;
            $plan->windowOffset = $query->offset;
            $plan->windowLimit = $query->limit;

            return;
        }

        if ($plan->adjustments !== [] && $query->hasCustomSort()) {
            $plan->reorderSkipped = RulePlan::SKIPPED_CUSTOM_SORT;
        }

        $reach = $query->offset + $query->limit;
        $wantsReordering = $plan->adjustments !== [] && $plan->reorderSkipped === null;

        // Reordering needs every result above the page, which is only affordable so far in. Past
        // that the page is still assembled where it has to be — only the reordering is given up.
        if ($wantsReordering && $reach > self::REORDER_LIMIT) {
            $plan->reorderSkipped = RulePlan::SKIPPED_BEYOND_WINDOW;
            $wantsReordering = false;
        }

        // A page reaching into the placed results has to be assembled whatever else happens: only
        // past placedReach() is the list organic enough to read at a shifted offset.
        if ($wantsReordering || $query->offset < $plan->placedReach()) {
            $plan->assembles = true;
            $plan->windowOffset = 0;
            $plan->windowLimit = $reach;
            $plan->reordered = $wantsReordering;

            // A result cannot be moved onto a page it was never read for, so the ones a rule moves
            // are asked for by name instead of hoping they fall inside the window. A search already
            // constraining results by ID has said which results it wants, so that is left alone.
            $plan->refetchesAdjusted = $wantsReordering && !$query->hasFilterOn('id');

            return;
        }

        $plan->assembles = false;
        $plan->windowOffset = $query->offset - $plan->placedCount();
        $plan->windowLimit = $query->limit;
    }

    /**
     * Records on every rule that asked for one that its adjustment did not decide this page's order.
     */
    private function recordSkippedAdjustments(RulePlan $plan): void
    {
        if ($plan->reorderSkipped === null || $plan->adjustments === []) {
            return;
        }

        $effects = $this->effectsByRule($plan);

        foreach ($plan->adjustments as $adjustment) {
            foreach (array_unique($adjustment['ruleIds']) as $ruleId) {
                ($effects[$ruleId] ?? null)?->addEffect([
                    'action' => 'adjustment',
                    'elementId' => $adjustment['elementId'],
                    'siteId' => $adjustment['siteId'],
                    'amount' => $adjustment['amount'],
                    'applied' => false,
                    'outcome' => $plan->reorderSkipped,
                ]);
            }
        }
    }

    /**
     * The hits the rules put in the list themselves. Each is an ordinary hit carrying an element and
     * a site, so it is loaded and authorized exactly like one the search found.
     *
     * @return array{pins:array<int,SearchHit>,promoted:SearchHit[]}
     */
    private function placements(RulePlan $plan, SearchIndex $index): array
    {
        $placed = ['pins' => [], 'promoted' => []];
        $effects = $this->effectsByRule($plan);

        foreach ($plan->promoted as $promotion) {
            $hit = $this->newHit($promotion, RuleActionType::Promote);
            $hit->promoted = true;
            $placed['promoted'][] = $hit;

            ($effects[$promotion['ruleId']] ?? null)?->addEffect([
                'action' => RuleActionType::Promote->value,
                'elementId' => $promotion['elementId'],
                'siteId' => $promotion['siteId'],
                'placed' => true,
            ]);
        }

        // Pins are spliced in last, at absolute positions, so a pin always outranks a promotion.
        ksort($plan->pins);

        foreach ($plan->pins as $position => $pin) {
            $hit = $this->newHit($pin, RuleActionType::Pin, $position);
            $hit->pinned = true;
            $placed['pins'][$position] = $hit;

            ($effects[$pin['ruleId']] ?? null)?->addEffect([
                'action' => RuleActionType::Pin->value,
                'elementId' => $pin['elementId'],
                'siteId' => $pin['siteId'],
                'position' => $position,
                'placed' => true,
            ]);
        }

        return $placed;
    }

    /**
     * @param array{elementId:int,elementType:string,siteId:int,ruleId:int} $target
     */
    private function newHit(array $target, RuleActionType $type, ?int $position = null): SearchHit
    {
        $hit = new SearchHit([
            'elementId' => $target['elementId'],
            'elementType' => $target['elementType'],
            'siteId' => $target['siteId'],
        ]);

        $hit->ruleEffects[] = array_filter([
            'action' => $type->value,
            'ruleId' => $target['ruleId'],
            'position' => $position,
        ], static fn(mixed $value) => $value !== null);

        return $hit;
    }

    /**
     * @param SearchHit[] $hits
     */
    private function adjust(array $hits, RulePlan $plan): void
    {
        foreach ($hits as $hit) {
            $adjustment = $plan->adjustmentFor($hit->elementId, $hit->siteId);

            if ($adjustment === 0.0) {
                continue;
            }

            // The provider's own score is left alone, so what it decided stays readable next to what
            // the rules did to it.
            $hit->scoreAdjustment += $adjustment;
            $hit->ruleEffects[] = ['action' => 'adjustment', 'amount' => $adjustment];
        }
    }

    /**
     * Orders by the score the rules left, keeping the provider's order wherever they are equal.
     *
     * @param SearchHit[] $hits
     */
    private function reorder(array &$hits): void
    {
        $ranked = [];

        foreach ($hits as $rank => $hit) {
            $ranked[] = [$rank, $hit];
        }

        usort($ranked, static function(array $a, array $b) {
            $comparison = $b[1]->getFinalScore() <=> $a[1]->getFinalScore();

            // The provider's own ranking breaks every tie, so nothing is ordered arbitrarily.
            return $comparison !== 0 ? $comparison : $a[0] <=> $b[0];
        });

        $hits = array_map(static fn(array $entry) => $entry[1], $ranked);
    }

    /**
     * @param SearchHit[] $hits
     * @param array{pins:array<int,SearchHit>,promoted:SearchHit[]} $placed
     * @return SearchHit[]
     */
    private function insert(array $hits, array $placed): array
    {
        $list = [...$placed['promoted'], ...$hits];

        foreach ($placed['pins'] as $position => $hit) {
            // A position past the end of a short list simply lands at the end of it.
            array_splice($list, min($position - 1, count($list)), 0, [$hit]);
        }

        return $list;
    }

    /**
     * Cuts a result read over a wider window back to the page that was asked for.
     */
    private function cut(SearchResult $result, SearchQuery $query, SearchQuery $execution, RulePlan $plan): void
    {
        if ($execution->offset === $query->offset && $execution->limit === $query->limit) {
            return;
        }

        $result->hits = array_slice(
            $result->hits,
            $plan->assembles ? $query->offset - $execution->offset : 0,
            $query->limit,
        );
    }

    /**
     * @return array<int,RuleEvaluation>
     */
    private function effectsByRule(RulePlan $plan): array
    {
        $effects = [];

        foreach ($plan->evaluations as $evaluation) {
            $effects[$evaluation->ruleId] = $evaluation;
        }

        return $effects;
    }

    private function record(RuleEvaluation $evaluation, RuleAction $action, bool $applied, string $outcome): void
    {
        $evaluation->addEffect([
            'action' => $action->type->value,
            'elementId' => $action->elementId,
            'siteId' => $action->siteId,
            'amount' => $action->type->adjustsScore() ? $action->adjustment() : null,
            'position' => $action->position,
            'applied' => $applied,
            'outcome' => $outcome,
        ]);
    }

    public function setRules(Rules $rules): void
    {
        $this->_rules = $rules;
    }

    public function getRules(): Rules
    {
        return $this->_rules ??= SearchKit::instance()->getRules();
    }

    public function setNormalization(Normalization $normalization): void
    {
        $this->_normalization = $normalization;
    }

    public function getNormalization(): Normalization
    {
        return $this->_normalization ??= SearchKit::instance()->getNormalization();
    }
}
