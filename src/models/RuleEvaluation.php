<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * What one rule did to one search, matched or not. Every search carries these, so why a result
 * ranked where it did can be explained without re-running anything.
 */
class RuleEvaluation extends Model
{
    public const MATCHED = 'matched';
    public const DISABLED = 'disabled';
    public const OUT_OF_SCHEDULE = 'outOfSchedule';
    public const OUT_OF_SCOPE = 'outOfScope';
    public const NO_MATCH = 'noMatch';

    public int $ruleId = 0;
    public string $ruleName = '';
    public int $priority = 0;
    public string $matchType = '';
    public string $matchValue = '';

    /** @var int|null The site this rule is confined to, or null when it applies in every one. */
    public ?int $siteId = null;

    public bool $matched = false;

    /** @var string Why it matched or did not, as one of the constants above. */
    public string $reason = self::NO_MATCH;

    /**
     * @var array<int,array<string,mixed>> What each of this rule's actions did, and where an action
     * was resolved away by a higher-priority rule, that it was.
     */
    public array $effects = [];

    /**
     * @param array<string,mixed> $effect
     */
    public function addEffect(array $effect): void
    {
        $this->effects[] = $effect;
    }

    public static function for(SearchRule $rule, bool $matched, string $reason): self
    {
        return new self([
            'ruleId' => (int)$rule->id,
            'ruleName' => (string)$rule,
            'priority' => $rule->priority,
            'matchType' => $rule->matchType->value,
            'matchValue' => $rule->matchValue,
            'siteId' => $rule->siteId,
            'matched' => $matched,
            'reason' => $reason,
        ]);
    }
}
