<?php

namespace Tahadudhiya\SearchKit\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\SearchKit\enums\SynonymType;
use Tahadudhiya\SearchKit\models\Synonym;
use Tahadudhiya\SearchKit\SearchKit;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Manages the words a search treats as the same thing.
 */
class SynonymsController extends Controller
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
        return $this->renderTemplate('search-kit/synonyms/_index', [
            'synonyms' => SearchKit::instance()->getSynonyms()->getAllSynonyms(),
            'indexes' => SearchKit::instance()->getIndexes()->getAllIndexes(),
            'canManage' => $this->canManage(),
        ]);
    }

    public function actionEdit(?int $synonymId = null, ?Synonym $synonym = null): Response
    {
        $synonym ??= $synonymId !== null
            ? SearchKit::instance()->getSynonyms()->getSynonymById($synonymId)
            : new Synonym();

        if ($synonym === null) {
            throw new NotFoundHttpException('Synonym not found.');
        }

        return $this->renderTemplate('search-kit/synonyms/_edit', [
            'synonym' => $synonym,
            'isNew' => $synonym->id === null,
            'indexes' => SearchKit::instance()->getIndexes()->getAllIndexes(),
            'typeOptions' => array_map(
                static fn(SynonymType $type) => ['label' => Craft::t('search-kit', $type->label()), 'value' => $type->value],
                SynonymType::cases(),
            ),
            'canManage' => $this->canManage(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(SearchKit::PERMISSION_MANAGE);

        $request = $this->request;
        $synonymId = $request->getBodyParam('synonymId');

        $synonym = $synonymId !== null
            ? SearchKit::instance()->getSynonyms()->getSynonymById((int)$synonymId)
            : new Synonym();

        if ($synonym === null) {
            throw new NotFoundHttpException('Synonym not found.');
        }

        $synonym->type = SynonymType::tryFrom((string)$request->getBodyParam('type')) ?? SynonymType::TwoWay;
        $synonym->terms = $this->words($request->getBodyParam('terms'));
        $synonym->replacements = $synonym->isTwoWay() ? [] : $this->words($request->getBodyParam('replacements'));
        $synonym->enabled = (bool)$request->getBodyParam('enabled', true);
        $synonym->sortOrder = max(0, (int)$request->getBodyParam('sortOrder', 0));

        $indexId = $request->getBodyParam('indexId');
        $synonym->indexId = $indexId !== null && $indexId !== '' ? (int)$indexId : null;

        $siteId = $request->getBodyParam('siteId');
        $synonym->siteId = $siteId !== null && $siteId !== '' ? (int)$siteId : null;

        if (!SearchKit::instance()->getSynonyms()->saveSynonym($synonym)) {
            $this->setFailFlash(Craft::t('search-kit', 'Couldn’t save synonym.'));
            Craft::$app->getUrlManager()->setRouteParams(['synonym' => $synonym]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('search-kit', 'Synonym saved.'));

        return $this->redirectToPostedUrl($synonym);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(SearchKit::PERMISSION_MANAGE);

        $synonymId = (int)$this->request->getRequiredBodyParam('synonymId');
        $synonym = SearchKit::instance()->getSynonyms()->getSynonymById($synonymId);

        if ($synonym === null) {
            throw new NotFoundHttpException('Synonym not found.');
        }

        SearchKit::instance()->getSynonyms()->deleteSynonym($synonym);
        $this->setSuccessFlash(Craft::t('search-kit', 'Synonym deleted.'));

        return $this->redirect('search-kit/synonyms');
    }

    /**
     * Words are posted as one comma-separated line, which is how a group reads.
     *
     * @return string[]
     */
    private function words(mixed $posted): array
    {
        if (!is_string($posted)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $posted))));
    }

    private function canManage(): bool
    {
        return Craft::$app->getUser()->checkPermission(SearchKit::PERMISSION_MANAGE);
    }
}
