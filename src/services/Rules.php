<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\enums\RuleMatchType;
use Tahadudhiya\SearchKit\models\RuleAction;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchRule;
use Tahadudhiya\SearchKit\records\RuleActionRecord;
use Tahadudhiya\SearchKit\records\SearchRuleRecord;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * The administrator-managed search rules. Every search reads these, so they are cached rather than
 * fetched again for each one.
 */
class Rules extends Component
{
    /** @var string Where the whole set is cached, since it is small and read by every search. */
    private const CACHE_KEY = 'searchkit:rules';

    /** @var SearchRule[]|null */
    private ?array $_rules = null;

    private ?Normalization $_normalization = null;

    /**
     * Every rule, in the order they are applied: highest priority first, then oldest first, so two
     * rules of equal priority never settle a conflict differently from one request to the next.
     *
     * @return SearchRule[]
     */
    public function getAllRules(): array
    {
        if ($this->_rules !== null) {
            return $this->_rules;
        }

        $cache = Craft::$app->getCache();
        $cached = $cache->get(self::CACHE_KEY);

        if (!is_array($cached)) {
            $cached = [
                'rules' => (new Query())
                    ->select(['id', 'indexId', 'siteId', 'name', 'enabled', 'priority', 'matchType',
                        'matchValue', 'dateStart', 'dateEnd', 'uid', ])
                    ->from([Table::RULES])
                    ->orderBy(['priority' => SORT_DESC, 'id' => SORT_ASC])
                    ->all(),
                'actions' => (new Query())
                    ->select(['id', 'ruleId', 'type', 'elementId', 'elementType', 'siteId', 'amount',
                        'position', 'value', 'sortOrder', 'uid', ])
                    ->from([Table::RULEACTIONS])
                    ->orderBy(['ruleId' => SORT_ASC, 'sortOrder' => SORT_ASC, 'id' => SORT_ASC])
                    ->all(),
            ];

            $cache->set(self::CACHE_KEY, $cached);
        }

        $actions = [];

        foreach ($cached['actions'] as $row) {
            $actions[(int)$row['ruleId']][] = $this->createActionFromRow($row);
        }

        return $this->_rules = array_map(
            fn(array $row) => $this->createRuleFromRow($row, $actions[(int)$row['id']] ?? []),
            $cached['rules'],
        );
    }

    public function getRuleById(int $id): ?SearchRule
    {
        foreach ($this->getAllRules() as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Every rule belonging to an index, whether or not it is enabled or in force.
     *
     * @return SearchRule[]
     */
    public function getRulesForIndex(int $indexId, ?int $siteId = null): array
    {
        return array_values(array_filter(
            $this->getAllRules(),
            static fn(SearchRule $rule) => $rule->appliesTo($indexId, $siteId),
        ));
    }

    /**
     * Saves a rule and everything it does as one thing: a rule left holding half its actions would
     * change results in a way nobody asked for.
     */
    public function saveRule(SearchRule $rule, bool $runValidation = true): bool
    {
        $rule->matchValue = $this->normalizeMatchValue($rule->matchType, $rule->matchValue);

        if (!$this->scopeExists($rule)) {
            return false;
        }

        // Targets are settled before validation, so an action is validated against the site it will
        // actually act in rather than the one the form happened to post.
        $index = $this->getIndexes()->getIndexById((int)$rule->indexId);

        if ($index === null || ($runValidation && !$this->resolveTargets($rule, $index))) {
            return false;
        }

        if ($runValidation && !$rule->validate()) {
            return false;
        }

        $wasNew = $rule->id === null;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record = $rule->id !== null ? SearchRuleRecord::findOne($rule->id) : new SearchRuleRecord();

            if ($record === null) {
                $rule->addError('id', "No search rule exists with the ID “{$rule->id}”.");
                $transaction->rollBack();

                return false;
            }

            $record->indexId = $rule->indexId;
            $record->siteId = $rule->siteId;
            $record->name = $rule->name;
            $record->enabled = $rule->enabled;
            $record->priority = $rule->priority;
            $record->matchType = $rule->matchType->value;
            $record->matchValue = $rule->matchValue;
            $record->dateStart = Db::prepareDateForDb($rule->dateStart);
            $record->dateEnd = Db::prepareDateForDb($rule->dateEnd);

            if (!$record->save()) {
                $rule->addErrors($record->getErrors());
                $transaction->rollBack();

                return false;
            }

            $rule->id = $record->id;
            $rule->uid = $record->uid;
            $this->saveActions($rule);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            if ($wasNew) {
                $rule->id = null;
            }

            throw $e;
        }

        $this->invalidate();

        return true;
    }

    public function deleteRule(SearchRule $rule): bool
    {
        if ($rule->id === null) {
            return false;
        }

        $record = SearchRuleRecord::findOne($rule->id);

        if ($record === null) {
            return false;
        }

        // The actions go with it through their foreign key.
        $deleted = (bool)$record->delete();
        $this->invalidate();

        return $deleted;
    }

    /**
     * Forgets the cached set, so the next search reads what was just saved.
     */
    public function invalidate(): void
    {
        $this->_rules = null;
        Craft::$app->getCache()->delete(self::CACHE_KEY);
    }

    /**
     * Rule text is normalized the way a query is, so a rule written with capitals or accents still
     * governs what someone types. A pattern keeps its wildcards, which normalization would strip.
     */
    public function normalizeMatchValue(RuleMatchType $type, string $value): string
    {
        if ($type !== RuleMatchType::Wildcard) {
            return $this->getNormalization()->normalize($value);
        }

        return implode('*', array_map(
            fn(string $part) => $this->getNormalization()->normalize($part),
            explode('*', $value),
        ));
    }

    /**
     * Rewrites the rule's actions. They are replaced rather than merged, so what is stored is
     * always exactly what was submitted.
     */
    private function saveActions(SearchRule $rule): void
    {
        Db::delete(Table::RULEACTIONS, ['ruleId' => $rule->id]);

        foreach ($rule->getActions() as $position => $action) {
            $record = new RuleActionRecord();
            $record->ruleId = (int)$rule->id;
            $record->type = $action->type->value;
            $record->elementId = $action->elementId;
            $record->elementType = $action->elementType;
            $record->siteId = $action->siteId;
            $record->amount = $action->amount;
            $record->position = $action->position;
            $record->value = $action->value;
            $record->sortOrder = $action->sortOrder !== 0 ? $action->sortOrder : $position;
            $record->save(false);

            $action->id = $record->id;
            $action->ruleId = (int)$rule->id;
            $action->uid = $record->uid;
        }
    }

    /**
     * Settles which site each action acts in and proves its target is real. Nothing a form posts is
     * trusted: an element that does not exist, is the wrong kind, is not something the rule's index
     * searches, or lives outside the rule's site is refused here rather than at search time.
     */
    private function resolveTargets(SearchRule $rule, SearchIndex $index): bool
    {
        $this->getSearchableFields()->attachFields($index);
        $indexed = $index->getElementTypes();
        $usable = true;

        foreach ($rule->getActions() as $action) {
            if (!$action->type->targetsElement()) {
                $action->elementId = $action->elementType = $action->siteId = null;
                continue;
            }

            $action->siteId = $rule->scopeSiteId($index->siteId);

            if ($action->elementId === null || $action->elementType === null) {
                // Left for the action's own validation to report in its own words.
                continue;
            }

            if (!in_array($action->elementType, $indexed, true)) {
                $rule->addError('actions', "“{$action->elementType}” is not something the “{$index->name}” search index covers.");
                $usable = false;
                continue;
            }

            if (!$this->targetExists($action->elementType, $action->elementId, $action->siteId, $index)) {
                $rule->addError('actions', "Result {$action->elementId} does not exist in this rule's site.");
                $usable = false;
            }
        }

        return $usable;
    }

    /**
     * Whether an element really is available to this rule: the right class, not in the trash, and
     * present in a site the rule may reach. A rule with no site of its own may name any site the
     * index covers, which is what stops a target crossing out of the index's scope.
     *
     * @param class-string<ElementInterface> $elementType
     */
    private function targetExists(string $elementType, int $elementId, ?int $siteId, SearchIndex $index): bool
    {
        if (!is_subclass_of($elementType, ElementInterface::class)) {
            return false;
        }

        // Status and trashed are set explicitly: a target may be unpublished today and published
        // tomorrow, but one that has been deleted can never come back.
        $query = $elementType::find()
            ->id($elementId)
            ->status(null)
            ->trashed(false)
            ->siteId($siteId ?? ($index->siteId ?? '*'));

        return $query->exists();
    }

    /**
     * Caught here rather than left to a foreign key, so callers get a validation error.
     */
    private function scopeExists(SearchRule $rule): bool
    {
        if ($rule->indexId === null || $this->getIndexes()->getIndexById($rule->indexId) === null) {
            $rule->addError('indexId', 'This rule needs a search index to govern.');
            return false;
        }

        if ($rule->siteId === null) {
            return true;
        }

        if (Craft::$app->getSites()->getSiteById($rule->siteId) === null) {
            $rule->addError('siteId', "No site exists with the ID “{$rule->siteId}”.");
            return false;
        }

        // A rule for a site its index does not cover could never govern anything.
        $index = $this->getIndexes()->getIndexById((int)$rule->indexId);

        if ($index !== null && !$index->coversSite($rule->siteId)) {
            $rule->addError('siteId', "The “{$index->name}” search index does not cover that site.");
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $row
     * @param RuleAction[] $actions
     */
    private function createRuleFromRow(array $row, array $actions): SearchRule
    {
        $rule = new SearchRule([
            'id' => (int)$row['id'],
            'indexId' => (int)$row['indexId'],
            'siteId' => $row['siteId'] !== null ? (int)$row['siteId'] : null,
            'name' => (string)$row['name'],
            'enabled' => (bool)$row['enabled'],
            'priority' => (int)$row['priority'],
            'matchType' => RuleMatchType::tryFrom((string)$row['matchType']) ?? RuleMatchType::Exact,
            'matchValue' => (string)$row['matchValue'],
            'dateStart' => $this->utc($row['dateStart']),
            'dateEnd' => $this->utc($row['dateEnd']),
            'uid' => (string)$row['uid'],
        ]);

        $rule->setActions($actions);

        return $rule;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function createActionFromRow(array $row): RuleAction
    {
        return new RuleAction([
            'id' => (int)$row['id'],
            'ruleId' => (int)$row['ruleId'],
            'type' => RuleActionType::tryFrom((string)$row['type']) ?? RuleActionType::Boost,
            'elementId' => $row['elementId'] !== null ? (int)$row['elementId'] : null,
            'elementType' => $row['elementType'] !== null ? (string)$row['elementType'] : null,
            'siteId' => $row['siteId'] !== null ? (int)$row['siteId'] : null,
            'amount' => (float)$row['amount'],
            'position' => $row['position'] !== null ? (int)$row['position'] : null,
            'value' => $row['value'] !== null ? (string)$row['value'] : null,
            'sortOrder' => (int)$row['sortOrder'],
            'uid' => (string)$row['uid'],
        ]);
    }

    /**
     * Dates are stored in UTC, so a schedule means the same moment however the site is read.
     */
    private function utc(mixed $value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeHelper::toDateTime($value, false, false);

        return $date !== false ? $date->setTimezone(new DateTimeZone('UTC')) : null;
    }

    public function setNormalization(Normalization $normalization): void
    {
        $this->_normalization = $normalization;
    }

    public function getNormalization(): Normalization
    {
        return $this->_normalization ??= $this->plugin()->getNormalization();
    }

    private function getIndexes(): Indexes
    {
        return $this->plugin()->getIndexes();
    }

    private function getSearchableFields(): SearchableFields
    {
        return $this->plugin()->getSearchableFields();
    }

    private function plugin(): SearchKit
    {
        return SearchKit::getInstance()
            ?? throw new InvalidConfigException('Search Kit is not installed or is disabled.');
    }
}
