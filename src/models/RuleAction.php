<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\ElementInterface;
use craft\base\Model;
use Tahadudhiya\SearchKit\enums\RuleActionType;

/**
 * One thing a matching rule does: move a result, place it, remove it, or send the search somewhere
 * else entirely.
 */
class RuleAction extends Model
{
    /** @var int How far down the list a pin may be placed, so a position cannot run away. */
    public const MAX_POSITION = 100;

    /** @var float The largest adjustment a boost or a bury may make. */
    public const MAX_AMOUNT = 100000.0;

    public ?int $id = null;
    public ?int $ruleId = null;

    public RuleActionType $type = RuleActionType::Boost;

    /** @var int|null The element this acts on, or null for a redirect, which acts on none. */
    public ?int $elementId = null;

    /** @var string|null The element's type, kept so a placed result can be loaded without a search. */
    public ?string $elementType = null;

    /**
     * @var int|null The only site this acts in, or null for every site the rule covers. A pin or a
     * promotion always names one, since it has to put the element somewhere.
     */
    public ?int $siteId = null;

    /** @var float How much a boost or a bury moves the result's score. */
    public float $amount = 0.0;

    /** @var int|null The 1-based position a pin places its element at. */
    public ?int $position = null;

    /** @var string|null Where a redirect sends the search. */
    public ?string $value = null;

    public int $sortOrder = 0;
    public ?string $uid = null;

    /**
     * What the action does to a score: a bury is a boost in the other direction, so the engine only
     * ever adds this.
     */
    public function adjustment(): float
    {
        return $this->type === RuleActionType::Bury ? -$this->amount : $this->amount;
    }

    protected function defineRules(): array
    {
        return [
            [['id', 'ruleId', 'elementId', 'siteId'], 'integer', 'min' => 1],
            [['sortOrder'], 'integer', 'min' => 0],
            // Every one of these has to reject a missing value, so none of them is skipped when empty.
            [['elementId'], 'validateTarget', 'skipOnEmpty' => false],
            [['amount'], 'validateAmount', 'skipOnEmpty' => false],
            [['position'], 'validatePosition', 'skipOnEmpty' => false],
            [['value'], 'validateRedirect', 'skipOnEmpty' => false],
        ];
    }

    /**
     * A target is only usable when the action names a real element type as well as an ID; a redirect
     * acts on no result at all, so carrying one would mean two different things were asked for.
     */
    public function validateTarget(string $attribute): void
    {
        if (!$this->type->targetsElement()) {
            if ($this->elementId !== null || $this->elementType !== null || $this->siteId !== null) {
                $this->addError($attribute, 'A redirect acts on the whole search, not on a result.');
            }

            return;
        }

        if ($this->elementId === null) {
            $this->addError($attribute, 'This action needs a result to act on.');
            return;
        }

        if ($this->elementType === null || !is_subclass_of($this->elementType, ElementInterface::class)) {
            $this->addError($attribute, 'This action needs to know what kind of result it acts on.');
            return;
        }

        // A placed result has to go somewhere in particular; a removal or an adjustment need not.
        if ($this->type->inserts() && $this->siteId === null) {
            $this->addError($attribute, 'A pinned or promoted result needs a site to be placed in.');
        }
    }

    public function validateAmount(string $attribute): void
    {
        if (!$this->type->adjustsScore()) {
            if ($this->amount !== 0.0) {
                $this->addError($attribute, 'Only a boost or a bury moves a result by an amount.');
            }

            return;
        }

        if ($this->amount <= 0 || $this->amount > self::MAX_AMOUNT) {
            $this->addError($attribute, 'An adjustment must be between 1 and ' . (int)self::MAX_AMOUNT . '.');
        }
    }

    public function validatePosition(string $attribute): void
    {
        if ($this->type !== RuleActionType::Pin) {
            if ($this->position !== null) {
                $this->addError($attribute, 'Only a pin places a result at a position.');
            }

            return;
        }

        if ($this->position === null || $this->position < 1 || $this->position > self::MAX_POSITION) {
            $this->addError($attribute, 'A pinned position must be between 1 and ' . self::MAX_POSITION . '.');
        }
    }

    /**
     * A redirect is only ever a path on this installation or an ordinary web address, so a rule
     * cannot be used to send a visitor to a script.
     */
    public function validateRedirect(string $attribute): void
    {
        if ($this->type !== RuleActionType::Redirect) {
            if ($this->value !== null && $this->value !== '') {
                $this->addError($attribute, 'Only a redirect names somewhere to send the search.');
            }

            return;
        }

        $value = trim((string)$this->value);

        if ($value === '') {
            $this->addError($attribute, 'A redirect needs somewhere to send the search.');
            return;
        }

        // A protocol-relative address reads as a path but lands on another host, so it is refused
        // along with every scheme other than http and https.
        $usable = (str_starts_with($value, '/') && !str_starts_with($value, '//'))
            || preg_match('#^https?://#i', $value) === 1;

        if (!$usable) {
            $this->addError($attribute, 'A redirect must be a path starting with “/” or an http(s) address.');
        }
    }
}
