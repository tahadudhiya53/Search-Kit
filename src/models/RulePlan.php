<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * Every conflict between matching rules settled, before a search runs. Deciding this up front is
 * what makes the outcome deterministic and what tells the search which results to leave out and how
 * wide a window the rules need.
 */
class RulePlan extends Model
{
    /** @var string Score adjustments were skipped because the search asked for its own ordering. */
    public const SKIPPED_CUSTOM_SORT = 'customSort';

    /** @var string Score adjustments were skipped because the page lies beyond the reordering window. */
    public const SKIPPED_BEYOND_WINDOW = 'beyondReorderWindow';

    /** @var string A placed result was not something this viewer may see, so it was not shown. */
    public const NOT_VIEWABLE = 'notViewable';

    /** @var RuleEvaluation[] Every rule considered, matched or not, in the order they were read. */
    public array $evaluations = [];

    /** @var string|null Where the highest-priority matching redirect sends this search. */
    public ?string $redirect = null;

    /** @var int|null The rule that claimed the redirect. */
    public ?int $redirectRuleId = null;

    /** @var array<string,array{elementId:int,siteId:int|null,ruleId:int}> Results left out entirely. */
    public array $hidden = [];

    /** @var array<int,array{elementId:int,elementType:string,siteId:int,ruleId:int}> Position => target. */
    public array $pins = [];

    /** @var array<string,array{elementId:int,elementType:string,siteId:int,ruleId:int}> Lifted results. */
    public array $promoted = [];

    /** @var array<string,array{elementId:int,siteId:int|null,amount:float,ruleIds:int[]}> Score moves. */
    public array $adjustments = [];

    /**
     * @var array<string,array{elementId:int,siteId:int|null,ruleId:int,action:string}> The one rule
     * that decided each result's fate, per site. A result may be hidden, pinned or promoted in a
     * site, never two of them.
     */
    public array $claims = [];

    /**
     * @var SearchHit[] The hits the rules put into this page. Kept so that what the explanation says
     * was placed can be settled against what the viewer was actually allowed to see.
     */
    public array $placedHits = [];

    /** @var bool Whether the page was assembled from the head of the list rather than read straight. */
    public bool $assembles = true;

    /** @var int|null Where the provider was asked to start, once the window was decided. */
    public ?int $windowOffset = null;

    /** @var int|null How many results the provider was asked for. */
    public ?int $windowLimit = null;

    /** @var bool Whether score adjustments reached the page that was asked for. */
    public bool $reordered = false;

    /**
     * @var bool Whether the results a boost or a bury moves are asked for in their own right. A
     * result cannot be lifted onto a page it was never read for, so they are held back from the
     * ranked list and fetched by name instead.
     */
    public bool $refetchesAdjusted = false;

    /** @var string|null Why they did not, as one of the constants above. */
    public ?string $reorderSkipped = null;

    /**
     * A result and the site it is in, as one key. A null site means every site, which is how a rule
     * that names no site of its own is held.
     */
    public static function key(int $elementId, ?int $siteId): string
    {
        return $elementId . ':' . ($siteId ?? '*');
    }

    /**
     * The rule that already decided this result's fate in this site, if one has. A claim naming no
     * site covers every site it did not already lose, so a rule written for one site settles that
     * site alone and leaves the rest to whatever else asked for them.
     *
     * @return array{elementId:int,siteId:int|null,ruleId:int,action:string}|null
     */
    public function claimedBy(int $elementId, ?int $siteId): ?array
    {
        $claim = $this->claims[self::key($elementId, $siteId)] ?? null;

        // A claim over every site is what a rule naming none makes; asking about every site is
        // answered by that alone, since a rule for one site settles that site and nothing more.
        if ($claim !== null || $siteId === null) {
            return $claim;
        }

        return $this->claims[self::key($elementId, null)] ?? null;
    }

    public function claim(int $elementId, ?int $siteId, int $ruleId, string $action): void
    {
        $this->claims[self::key($elementId, $siteId)] = [
            'elementId' => $elementId,
            'siteId' => $siteId,
            'ruleId' => $ruleId,
            'action' => $action,
        ];
    }

    /**
     * How far the rules move a score for one result in one site, counting only the rules whose own
     * site covers it.
     */
    public function adjustmentFor(int $elementId, ?int $siteId): float
    {
        $amount = 0.0;

        foreach ([self::key($elementId, $siteId), self::key($elementId, null)] as $key) {
            $amount += $this->adjustments[$key]['amount'] ?? 0.0;
        }

        return $amount;
    }

    /**
     * Every result the provider must leave out: the hidden ones, and the ones the rules place
     * themselves, which are put back at their own positions afterwards.
     *
     * @return array<int,array{elementId:int,siteId:int|null}>
     */
    public function excludedElements(): array
    {
        $excluded = [];

        foreach ($this->hidden as $target) {
            $excluded[] = ['elementId' => $target['elementId'], 'siteId' => $target['siteId']];
        }

        foreach ($this->placed() as $target) {
            $excluded[] = ['elementId' => $target['elementId'], 'siteId' => $target['siteId']];
        }

        if ($this->refetchesAdjusted) {
            foreach ($this->adjustedElements() as $target) {
                $excluded[] = $target;
            }
        }

        return $excluded;
    }

    /**
     * The results a boost or a bury moves, each with the site it is moved in.
     *
     * @return array<int,array{elementId:int,siteId:int|null}>
     */
    public function adjustedElements(): array
    {
        return array_values(array_map(
            static fn(array $adjustment) => [
                'elementId' => $adjustment['elementId'],
                'siteId' => $adjustment['siteId'],
            ],
            $this->adjustments,
        ));
    }

    /**
     * The results the rules put in the list themselves, pins first.
     *
     * @return array<int,array{elementId:int,elementType:string,siteId:int,ruleId:int}>
     */
    public function placed(): array
    {
        return [...array_values($this->pins), ...array_values($this->promoted)];
    }

    public function placedCount(): int
    {
        return count($this->pins) + count($this->promoted);
    }

    /**
     * How far down the final list a placed result can reach. Past this point the list is nothing but
     * organic results, which is what lets a deep page be read without assembling everything above it.
     */
    public function placedReach(): int
    {
        return max([0, $this->placedCount(), ...array_keys($this->pins)]);
    }

    /**
     * Whether anything here changes what the provider is asked for or what comes back. A rule that
     * only redirects leaves the search itself alone.
     */
    public function affectsSearch(): bool
    {
        return $this->hidden !== [] || $this->rearrangesResults();
    }

    /**
     * Whether the results that do come back have to be rearranged. Hidden results never arrive at
     * all, so removing them is not rearranging anything.
     */
    public function rearrangesResults(): bool
    {
        return $this->pins !== [] || $this->promoted !== [] || $this->adjustments !== [];
    }

    /**
     * @return RuleEvaluation[]
     */
    public function getMatchedRules(): array
    {
        return array_values(array_filter(
            $this->evaluations,
            static fn(RuleEvaluation $evaluation) => $evaluation->matched,
        ));
    }
}
