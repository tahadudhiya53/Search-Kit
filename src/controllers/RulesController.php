<?php

namespace Tahadudhiya\SearchKit\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use DateTime;
use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\enums\RuleMatchType;
use Tahadudhiya\SearchKit\models\RuleAction;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchRule;
use Tahadudhiya\SearchKit\SearchKit;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Manages the rules that decide what a search deliberately returns.
 */
class RulesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(SearchKit::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('search-kit/rules/_index', [
            'rules' => SearchKit::instance()->getRules()->getAllRules(),
            'indexes' => SearchKit::instance()->getIndexes()->getAllIndexes(),
            'canManage' => $this->canManage(),
        ]);
    }

    public function actionEdit(?int $ruleId = null, ?SearchRule $rule = null): Response
    {
        $rule ??= $ruleId !== null
            ? SearchKit::instance()->getRules()->getRuleById($ruleId)
            : new SearchRule();

        if ($rule === null) {
            throw new NotFoundHttpException('Search rule not found.');
        }

        $index = $rule->indexId !== null ? SearchKit::instance()->getIndexes()->getIndexById($rule->indexId) : null;

        return $this->renderTemplate('search-kit/rules/_edit', [
            'rule' => $rule,
            'isNew' => $rule->id === null,
            // A placed result has to be put in one site, so the pickers only open once the rule and
            // its index have settled which site that is.
            'placementSiteId' => $index !== null ? $rule->scopeSiteId($index->siteId) : null,
            'indexes' => SearchKit::instance()->getIndexes()->getAllIndexes(),
            'elementTypes' => $index !== null ? $this->elementTypes($index) : [],
            'actionTypes' => array_combine(
                array_map(static fn(RuleActionType $type) => $type->value, RuleActionType::cases()),
                RuleActionType::cases(),
            ),
            'matchOptions' => array_map(
                static fn(RuleMatchType $type) => ['label' => Craft::t('search-kit', $type->label()), 'value' => $type->value],
                RuleMatchType::cases(),
            ),
            'canManage' => $this->canManage(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(SearchKit::PERMISSION_MANAGE_RULES);

        $request = $this->request;
        $ruleId = $request->getBodyParam('ruleId');

        $rule = $ruleId !== null
            ? SearchKit::instance()->getRules()->getRuleById((int)$ruleId)
            : new SearchRule();

        if ($rule === null) {
            throw new NotFoundHttpException('Search rule not found.');
        }

        $rule->name = trim((string)$request->getBodyParam('name'));
        $rule->enabled = (bool)$request->getBodyParam('enabled', true);
        $rule->priority = $this->wholeNumber($request->getBodyParam('priority', 0));
        $rule->matchType = RuleMatchType::tryFrom((string)$request->getBodyParam('matchType')) ?? RuleMatchType::Exact;
        $rule->matchValue = (string)$request->getBodyParam('matchValue');
        $rule->dateStart = $this->date($request->getBodyParam('dateStart'));
        $rule->dateEnd = $this->date($request->getBodyParam('dateEnd'));

        $indexId = $request->getBodyParam('indexId');
        $rule->indexId = $indexId !== null && $indexId !== '' ? (int)$indexId : null;

        $siteId = $request->getBodyParam('siteId');
        $rule->siteId = $siteId !== null && $siteId !== '' ? (int)$siteId : null;

        $rule->setActions($this->postedActions($rule));

        if (!SearchKit::instance()->getRules()->saveRule($rule)) {
            $this->setFailFlash(Craft::t('search-kit', 'Couldn’t save rule.'));
            Craft::$app->getUrlManager()->setRouteParams(['rule' => $rule]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('search-kit', 'Rule saved.'));

        return $this->redirectToPostedUrl($rule);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(SearchKit::PERMISSION_MANAGE_RULES);

        $ruleId = (int)$this->request->getRequiredBodyParam('ruleId');
        $rule = SearchKit::instance()->getRules()->getRuleById($ruleId);

        if ($rule === null) {
            throw new NotFoundHttpException('Search rule not found.');
        }

        SearchKit::instance()->getRules()->deleteRule($rule);
        $this->setSuccessFlash(Craft::t('search-kit', 'Rule deleted.'));

        return $this->redirect('search-kit/rules');
    }

    /**
     * The actions posted by the form. Each kind of control is its own set of chosen results, so the
     * form needs no scripting and the stored model still holds one row per result.
     *
     * @return RuleAction[]
     */
    private function postedActions(SearchRule $rule): array
    {
        $posted = $this->request->getBodyParam('results');
        $allowed = $rule->indexId !== null
            ? array_keys($this->elementTypes(SearchKit::instance()->getIndexes()->getIndexById($rule->indexId)))
            : [];
        $actions = [];
        $sortOrder = 0;

        foreach (RuleActionType::cases() as $type) {
            if ($type === RuleActionType::Redirect) {
                continue;
            }

            $amount = (float)$this->request->getBodyParam("amounts.{$type->value}", 0);
            $position = (int)$this->request->getBodyParam('pinPosition', 1);

            foreach ($this->elementIds($posted, $type, $allowed) as $elementType => $elementIds) {
                foreach ($elementIds as $offset => $elementId) {
                    $action = new RuleAction([
                        'type' => $type,
                        'elementId' => $elementId,
                        'elementType' => $elementType,
                        'sortOrder' => $sortOrder++,
                    ]);

                    $action->amount = $type->adjustsScore() ? $amount : 0.0;

                    // Several pinned results run down the list from the position that was chosen.
                    $action->position = $type === RuleActionType::Pin ? $position + $offset : null;

                    // The site is settled by the service against the rule and its index; nothing a
                    // form posts decides which site a result is taken from.

                    $actions[] = $action;
                }
            }
        }

        $redirect = trim((string)$this->request->getBodyParam('redirect', ''));

        if ($redirect !== '') {
            $actions[] = new RuleAction([
                'type' => RuleActionType::Redirect,
                'value' => $redirect,
                'sortOrder' => $sortOrder,
            ]);
        }

        return $actions;
    }

    /**
     * Element IDs the form chose for one kind of control, keyed by the element type they came from.
     *
     * @param string[] $allowed The element types the rule's index searches.
     * @return array<string,int[]>
     */
    private function elementIds(mixed $posted, RuleActionType $type, array $allowed): array
    {
        if (!is_array($posted) || !isset($posted[$type->value]) || !is_array($posted[$type->value])) {
            return [];
        }

        $chosen = [];

        foreach ($posted[$type->value] as $key => $ids) {
            // Matched against the types this index already searches rather than resolved as a class
            // name, so nothing a form posts decides which class is loaded.
            $elementType = $this->matchElementType((string)$key, $allowed);

            if ($elementType === null || !is_array($ids)) {
                continue;
            }

            $values = array_values(array_filter(array_map('intval', $ids)));

            if ($values !== []) {
                $chosen[$elementType] = $values;
            }
        }

        return $chosen;
    }

    /**
     * @param string[] $allowed
     */
    private function matchElementType(string $posted, array $allowed): ?string
    {
        foreach ($allowed as $elementType) {
            if (str_replace('\\', '-', $elementType) === $posted) {
                return $elementType;
            }
        }

        return null;
    }

    /**
     * A posted whole number, or zero. A value that is not one is a mistake rather than a priority.
     */
    private function wholeNumber(mixed $posted): int
    {
        $number = is_int($posted) || is_string($posted) ? filter_var($posted, FILTER_VALIDATE_INT) : false;

        return $number !== false ? $number : -1;
    }

    /**
     * The element types a rule's results may be chosen from: the ones its index actually searches.
     *
     * @return array<string,string> Element type => its display name.
     */
    private function elementTypes(?SearchIndex $index): array
    {
        if ($index === null) {
            return [];
        }

        SearchKit::instance()->getSearchableFields()->attachFields($index);
        $types = [];

        foreach ($index->getElementTypes() as $elementType) {
            /** @var class-string<ElementInterface> $elementType */
            $types[$elementType] = $elementType::pluralDisplayName();
        }

        return $types;
    }

    /**
     * A schedule posted by the control panel, read in the system timezone and stored as UTC, so it
     * means the moment an administrator chose rather than the one the server happened to be in.
     */
    private function date(mixed $posted): ?DateTime
    {
        if ($posted === null || $posted === '' || $posted === []) {
            return null;
        }

        $date = DateTimeHelper::toDateTime($posted, true);

        return $date !== false ? $date : null;
    }

    private function canManage(): bool
    {
        return Craft::$app->getUser()->checkPermission(SearchKit::PERMISSION_MANAGE_RULES);
    }
}
