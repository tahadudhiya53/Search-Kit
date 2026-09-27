<?php

namespace Tahadudhiya\SearchKit\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\SearchKit\models\ApiKey;
use Tahadudhiya\SearchKit\SearchKit;
use Tahadudhiya\SearchKit\services\ApiKeys;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Manages the keys the search API is reached with. A key is shown once, when it is created, and
 * never again.
 */
class ApiKeysController extends Controller
{
    /** @var string Where a newly created key waits for the page that shows it, the once. */
    public const FLASH_NEW_KEY = 'searchKitApiKey';

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(SearchKit::PERMISSION_MANAGE_API_KEYS);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('search-kit/apikeys/_index', [
            'keys' => SearchKit::instance()->getApiKeys()->getAllKeys(),
            'indexes' => SearchKit::instance()->getIndexes()->getAllIndexes(),
            'newKey' => Craft::$app->getSession()->getFlash(self::FLASH_NEW_KEY),
        ]);
    }

    public function actionEdit(?int $keyId = null, ?ApiKey $key = null): Response
    {
        $key ??= $keyId !== null
            ? SearchKit::instance()->getApiKeys()->getKeyById($keyId)
            : new ApiKey(['rateLimit' => ApiKeys::DEFAULT_RATE_LIMIT]);

        if ($key === null) {
            throw new NotFoundHttpException('API key not found.');
        }

        return $this->renderTemplate('search-kit/apikeys/_edit', [
            'key' => $key,
            'isNew' => $key->id === null,
            'indexes' => SearchKit::instance()->getIndexes()->getAllIndexes(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = $this->request;
        $keyId = $request->getBodyParam('keyId');

        $key = $keyId !== null && $keyId !== ''
            ? SearchKit::instance()->getApiKeys()->getKeyById((int)$keyId)
            : new ApiKey();

        if ($key === null) {
            throw new NotFoundHttpException('API key not found.');
        }

        $key->name = trim((string)$request->getBodyParam('name'));
        $key->enabled = (bool)$request->getBodyParam('enabled', true);
        $key->indexIds = $this->indexIds($request->getBodyParam('indexIds'));

        $rateLimit = trim((string)$request->getBodyParam('rateLimit'));
        $key->rateLimit = $rateLimit !== '' ? (int)$rateLimit : null;

        $isNew = $key->id === null;
        $plainKey = $isNew ? SearchKit::instance()->getApiKeys()->createKey($key) : null;

        if ($isNew ? $plainKey === null : !SearchKit::instance()->getApiKeys()->saveKey($key)) {
            $this->setFailFlash(Craft::t('search-kit', 'Couldn’t save API key.'));
            Craft::$app->getUrlManager()->setRouteParams(['key' => $key]);

            return null;
        }

        if ($plainKey !== null) {
            // The only moment the key exists. It is handed to the page that shows it and to
            // nothing else — it is never stored, logged or shown again.
            Craft::$app->getSession()->setFlash(self::FLASH_NEW_KEY, $plainKey);
        }

        $this->setSuccessFlash(Craft::t('search-kit', 'API key saved.'));

        return $this->redirect('search-kit/api-keys');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $keyId = (int)$this->request->getRequiredBodyParam('keyId');
        $key = SearchKit::instance()->getApiKeys()->getKeyById($keyId);

        if ($key === null) {
            throw new NotFoundHttpException('API key not found.');
        }

        SearchKit::instance()->getApiKeys()->deleteKey($key);
        $this->setSuccessFlash(Craft::t('search-kit', 'API key revoked.'));

        return $this->redirect('search-kit/api-keys');
    }

    /**
     * The indexes a key may search, as posted. Nothing is trusted: only the IDs of indexes that
     * actually exist are kept, so a key can never be scoped to something that is not there.
     *
     * @return int[]
     */
    private function indexIds(mixed $posted): array
    {
        if (!is_array($posted)) {
            return [];
        }

        $existing = array_map(static fn($index) => (int)$index->id, SearchKit::instance()->getIndexes()->getAllIndexes());

        return array_values(array_intersect($existing, array_map('intval', $posted)));
    }
}
