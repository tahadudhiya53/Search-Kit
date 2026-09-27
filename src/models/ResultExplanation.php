<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * Why one result is where it is: what it matched, what the provider scored it, and what the rules
 * then did to it. Every value here was produced by the search that returned the result.
 */
class ResultExplanation extends Model
{
    /** @var string The provider ranked it by score, which the rules may have moved. */
    public const BY_SCORE = 'score';

    /** @var string A rule placed it at a position of its own; no score decided where it is. */
    public const BY_PIN = 'pin';

    /** @var string A rule put it above the ranked results; no score decided where it is. */
    public const BY_PROMOTION = 'promotion';

    /** @var int Where the result sits in the final list, counted from one. */
    public int $position = 0;

    /** @var string What put it there, as one of the constants above. */
    public string $rankedBy = self::BY_SCORE;

    /** @var int|null The position a pin asked for, for a result a pin placed. */
    public ?int $pinnedPosition = null;

    public int $elementId = 0;
    public ?int $siteId = null;
    public ?string $elementType = null;
    public ?string $title = null;

    /**
     * @var array<string,int|null> The fields the match was found in, each with the weight the index
     * gives it. A weight is null for a field the index no longer configures.
     */
    public array $matchedFields = [];

    /** @var float The provider's own score, exactly as it reported it. A placed result has none. */
    public float $score = 0.0;

    /** @var float How far the rules moved that score. */
    public float $scoreAdjustment = 0.0;

    /** @var float What the result was ranked by. */
    public float $finalScore = 0.0;

    public bool $pinned = false;
    public bool $promoted = false;

    /** @var array<int,array<string,mixed>> What each rule did to this result. */
    public array $ruleEffects = [];

    public function wasPlaced(): bool
    {
        return $this->pinned || $this->promoted;
    }

    /**
     * Whether the provider scored this result at all. A result a rule placed was held back from the
     * search, so it has no score of its own and none may be invented for it.
     */
    public function hasProviderScore(): bool
    {
        return !$this->wasPlaced();
    }

    public function wasAdjusted(): bool
    {
        return $this->scoreAdjustment !== 0.0;
    }
}
