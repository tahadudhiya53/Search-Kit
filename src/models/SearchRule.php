<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\enums\RuleMatchType;

/**
 * One deliberate instruction about what a search should return: which queries it governs, when it
 * is in force, and what it does to the results.
 */
class SearchRule extends Model
{
    /** @var int The lowest and highest priority a rule may be given. */
    public const MIN_PRIORITY = 0;
    public const MAX_PRIORITY = 1000;

    public ?int $id = null;

    /** @var int|null The index this rule governs. A rule always belongs to exactly one. */
    public ?int $indexId = null;

    /** @var int|null The only site this applies in, or null to apply in every site. */
    public ?int $siteId = null;

    public string $name = '';
    public bool $enabled = true;

    /** @var int Higher runs first; rules of equal priority run oldest first. */
    public int $priority = 0;

    public RuleMatchType $matchType = RuleMatchType::Exact;

    /** @var string The query text this rule is triggered by, normalized the way a query is. */
    public string $matchValue = '';

    /** @var DateTime|null When this rule comes into force, or null for straight away. */
    public ?DateTime $dateStart = null;

    /** @var DateTime|null When this rule stops, or null for never. */
    public ?DateTime $dateEnd = null;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** @var RuleAction[] */
    private array $_actions = [];

    /**
     * @return RuleAction[]
     */
    public function getActions(): array
    {
        return $this->_actions;
    }

    /**
     * @param RuleAction[] $actions
     */
    public function setActions(array $actions): void
    {
        $this->_actions = array_values($actions);
    }

    /**
     * @return RuleAction[]
     */
    public function getActionsOfType(RuleActionType $type): array
    {
        return array_values(array_filter(
            $this->_actions,
            static fn(RuleAction $action) => $action->type === $type,
        ));
    }

    /**
     * Whether this rule is read at all for a search of this index in this site. A search of every
     * site still reads a rule written for one of them — what that rule may touch is settled per
     * result, by the site each of its actions carries.
     */
    public function appliesTo(int $indexId, ?int $siteId): bool
    {
        if ($this->indexId !== $indexId) {
            return false;
        }

        return $this->siteId === null || $siteId === null || $this->siteId === $siteId;
    }

    /**
     * The site this rule's actions act in, or null when it acts in every site the search covers.
     */
    public function scopeSiteId(?int $indexSiteId): ?int
    {
        return $this->siteId ?? $indexSiteId;
    }

    /**
     * Whether the schedule has this rule in force. Both dates are held in UTC, so a rule keeps the
     * moment it was given whatever timezone the site is read in.
     */
    public function isInForce(?DateTimeInterface $now = null): bool
    {
        $now ??= new DateTime('now', new DateTimeZone('UTC'));

        if ($this->dateStart !== null && $now < $this->dateStart) {
            return false;
        }

        return $this->dateEnd === null || $now <= $this->dateEnd;
    }

    /**
     * Whether this rule is triggered by a query. The text given here is already normalized, which
     * is what lets a rule written as “iPhone” govern a search for “iphone”.
     */
    public function matches(string $normalizedQuery): bool
    {
        return $this->matchType->matches($normalizedQuery, $this->matchValue);
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : $this->matchValue;
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'indexId'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['id', 'indexId', 'siteId'], 'integer', 'min' => 1],
            [['priority'], 'integer', 'min' => self::MIN_PRIORITY, 'max' => self::MAX_PRIORITY],
            // Empty text is exactly what needs rejecting, so validation is not skipped for it.
            [['matchValue'], 'validateMatchValue', 'skipOnEmpty' => false],
            [['dateEnd'], 'validateSchedule'],
            [['actions'], 'validateActions', 'skipOnEmpty' => false],
        ];
    }

    public function validateMatchValue(string $attribute): void
    {
        if (trim($this->matchValue) === '') {
            $this->addError($attribute, 'A rule needs query text to be triggered by.');
            return;
        }

        // A pattern of nothing but wildcards would govern every search, which no one writes on purpose.
        if ($this->matchType === RuleMatchType::Wildcard && trim($this->matchValue, '* ') === '') {
            $this->addError($attribute, 'A pattern needs something to match besides wildcards.');
        }
    }

    public function validateSchedule(string $attribute): void
    {
        if ($this->dateStart !== null && $this->dateEnd !== null && $this->dateEnd <= $this->dateStart) {
            $this->addError($attribute, 'A rule cannot end before it starts.');
        }
    }

    public function validateActions(string $attribute): void
    {
        if ($this->_actions === []) {
            $this->addError($attribute, 'A rule needs at least one thing to do.');
            return;
        }

        foreach ($this->_actions as $action) {
            if (!$action->validate()) {
                foreach ($action->getErrorSummary(true) as $error) {
                    $this->addError($attribute, $action->type->label() . ': ' . $error);
                }
            }
        }
    }
}
