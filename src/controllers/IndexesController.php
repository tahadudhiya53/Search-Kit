<?php

namespace Tahadudhiya\SearchKit\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\web\Controller;
use Tahadudhiya\SearchKit\base\SearchProviderInterface;
use Tahadudhiya\SearchKit\enums\PartialMatchMode;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\models\AnalyticsSettings;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchSettings;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\SearchKit;
use yii\base\Model;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Manages search indexes, their searchable fields, and the indexing work they owe.
 */
class IndexesController extends Controller
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
        $plugin = SearchKit::instance();
        $indexes = $plugin->getIndexes()->getAllIndexes();
        $statuses = [];

        foreach ($indexes as $index) {
            $statuses[$index->handle] = $plugin->getIndexing()->getStatus($index);
        }

        return $this->renderTemplate('search-kit/_index', [
            'indexes' => $indexes,
            'statuses' => $statuses,
            'canManage' => $this->canManage(),
            'canRebuild' => $this->canRebuild(),
        ]);
    }

    public function actionEdit(?int $indexId = null, ?SearchIndex $index = null): Response
    {
        $plugin = SearchKit::instance();
        $index ??= $indexId !== null
            ? $plugin->getIndexes()->getIndexById($indexId)
            : new SearchIndex(['provider' => CraftProvider::class]);

        if ($index === null) {
            throw new NotFoundHttpException('Search index not found.');
        }

        $plugin->getSearchableFields()->attachFields($index);

        $elementTypeGroups = [];

        foreach ($plugin->getSearchableFields()->getIndexableElementTypes() as $elementType) {
            /** @var class-string<ElementInterface> $elementType */
            $elementTypeGroups[] = [
                'type' => $elementType,
                'label' => $elementType::displayName(),
                'handles' => $plugin->getSearchableFields()->getAvailableHandles($elementType),
            ];
        }


        return $this->renderTemplate('search-kit/_edit', [
            'index' => $index,
            'isNew' => $index->id === null,
            'providerOptions' => $this->providerOptions(),
            'providerSettings' => $this->providerSettingsForms($index),
            'providerCapabilities' => $this->providerCapabilities(),
            'elementTypeGroups' => $elementTypeGroups,
            'partialMatchOptions' => $this->partialMatchOptions(),
            'status' => $index->id !== null ? $plugin->getIndexing()->getStatus($index) : null,
            'failures' => $index->id !== null ? $plugin->getIndexOperations()->getFailed($index->id) : [],
            'termCount' => $index->id !== null ? $plugin->getTerms()->countForIndex($index->id) : 0,
            'canManage' => $this->canManage(),
            'canRebuild' => $this->canRebuild(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(SearchKit::PERMISSION_MANAGE);

        $plugin = SearchKit::instance();
        $request = $this->request;
        $indexId = $request->getBodyParam('indexId');

        $index = $indexId !== null
            ? $plugin->getIndexes()->getIndexById((int)$indexId)
            : new SearchIndex();

        if ($index === null) {
            throw new NotFoundHttpException('Search index not found.');
        }

        $index->name = (string)$request->getBodyParam('name', $index->name);
        $index->handle = (string)$request->getBodyParam('handle', $index->handle);
        $index->provider = (string)$request->getBodyParam('provider', $index->provider);
        $index->enabled = (bool)$request->getBodyParam('enabled', true);

        $siteId = $request->getBodyParam('siteId');
        $index->siteId = $siteId !== null && $siteId !== '' ? (int)$siteId : null;

        $index->settings = $this->resolveProviderSettings(
            $request->getBodyParam('providerTypes', []),
            $request->getBodyParam('providerSettings', []),
            $index->provider,
        );

        $index->setSearchSettings($this->resolveSearchSettings($request->getBodyParam('searchSettings')));
        $index->setAnalyticsSettings($this->resolveAnalyticsSettings($request->getBodyParam('analyticsSettings')));

        $fields = $this->resolveFields(
            $request->getBodyParam('elementTypes', []),
            $request->getBodyParam('fields', []),
        );

        if (!$plugin->getIndexes()->saveIndexConfiguration($index, $fields)) {
            return $this->failure($index, $fields);
        }

        $this->setSuccessFlash(Craft::t('search-kit', 'Search index saved.'));

        return $this->redirectToPostedUrl($index);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(SearchKit::PERMISSION_MANAGE);

        $index = $this->requireIndex();
        SearchKit::instance()->getIndexes()->deleteIndex($index);
        $this->setSuccessFlash(Craft::t('search-kit', 'Search index deleted.'));

        return $this->redirect('search-kit/indexes');
    }

    public function actionRebuild(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(SearchKit::PERMISSION_REBUILD);

        $index = $this->requireIndex();
        SearchKit::instance()->getIndexing()->queueRebuild($index);
        $this->setSuccessFlash(Craft::t('search-kit', 'Rebuild queued.'));

        return $this->redirectToPostedUrl();
    }

    public function actionRetryFailed(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(SearchKit::PERMISSION_REBUILD);

        $index = $this->requireIndex();
        $reset = SearchKit::instance()->getIndexing()->retryFailed($index);
        $this->setSuccessFlash(Craft::t('search-kit', '{count} operations queued for retry.', ['count' => $reset]));

        return $this->redirectToPostedUrl();
    }

    /**
     * What each provider can do, so an index's behaviour is visible where it is chosen rather than
     * only discovered when a search is refused.
     *
     * @return array<string,string[]>
     */
    private function providerCapabilities(): array
    {
        $capabilities = [];

        foreach (SearchKit::instance()->getProviders()->getAllProviderTypes() as $providerType) {
            /** @var class-string<SearchProviderInterface> $providerType */
            $capabilities[$providerType] = array_map(
                static fn(ProviderCapability $capability) => $capability->label(),
                $providerType::capabilities(),
            );
        }

        return $capabilities;
    }

    private function providerOptions(): array
    {
        return array_map(
            /** @param class-string<SearchProviderInterface> $providerType */
            static fn(string $providerType) => [
                'label' => $providerType::displayName(),
                'value' => $providerType,
            ],
            SearchKit::instance()->getProviders()->getAllProviderTypes(),
        );
    }

    /**
     * The settings form each provider asks for, namespaced so two providers cannot post over each
     * other. A provider needing no settings contributes no form.
     *
     * @return array<array{type:string,html:string}>
     */
    private function providerSettingsForms(SearchIndex $index): array
    {
        $view = Craft::$app->getView();
        $forms = [];

        foreach (SearchKit::instance()->getProviders()->getAllProviderTypes() as $i => $providerType) {
            // The chosen provider is built with what is saved, so the form shows the real settings.
            $chosen = $providerType === $index->provider;
            $provider = SearchKit::instance()->getProviders()->createProviderOfType($providerType, $chosen ? $index->settings : []);

            // A save that failed comes back through here, so the provider re-reports which of its
            // own settings were wrong rather than only the index saying that something was.
            if ($chosen && $index->hasErrors('settings') && $provider instanceof Model) {
                $provider->validate();
            }

            // Namespaced around the rendering rather than over the output: a settings field that
            // builds its own input in JavaScript is only named correctly from inside the namespace.
            $html = $view->namespaceInputs(
                static fn() => (string)$provider->getSettingsHtml(),
                "providerSettings[$i]",
            );

            if (trim($html) !== '') {
                $forms[] = ['type' => $providerType, 'html' => $html];
            }
        }

        return $forms;
    }

    /**
     * The settings posted for the provider that was chosen, and nothing else. Element types travel
     * as posted values rather than as array keys, so a class name never has to survive being used
     * as an input name, and only a provider that is actually offered is read.
     *
     * @return array<string,mixed>
     */
    private function resolveProviderSettings(mixed $postedTypes, mixed $postedSettings, string $provider): array
    {
        if (!is_array($postedTypes) || !is_array($postedSettings)) {
            return [];
        }

        $offered = SearchKit::instance()->getProviders()->getAllProviderTypes();

        foreach ($postedTypes as $group => $type) {
            if ($type !== $provider || !in_array($type, $offered, true)) {
                continue;
            }

            $posted = $postedSettings[$group] ?? [];

            if (!is_array($posted)) {
                return [];
            }

            // Only what the provider declares as a setting, so nothing posted can reach it as an
            // arbitrary property.
            $allowed = array_flip(SearchKit::instance()->getProviders()->createProviderOfType($type)->settingsAttributes());

            return array_filter(
                array_intersect_key($posted, $allowed),
                static fn(mixed $value) => is_scalar($value),
            );
        }

        return [];
    }

    /**
     * How this index treats the text it is searched with. Nothing posted here changes what is
     * indexed, so a change to it never costs a rebuild, and nothing posted here is coerced: a
     * value that cannot be read is reported rather than saved as something else.
     */
    private function resolveSearchSettings(mixed $posted): SearchSettings
    {
        return SearchSettings::fromInput(is_array($posted) ? $posted : []);
    }

    /**
     * What this index records about its searches. Nothing posted here is coerced either.
     */
    private function resolveAnalyticsSettings(mixed $posted): AnalyticsSettings
    {
        return AnalyticsSettings::fromInput(is_array($posted) ? $posted : []);
    }

    /**
     * @return array<array{label:string,value:string}>
     */
    private function partialMatchOptions(): array
    {
        $labels = [
            PartialMatchMode::Off->value => 'Whole words only',
            PartialMatchMode::Prefix->value => 'The start of a word',
            PartialMatchMode::Substring->value => 'Anywhere in a word',
        ];

        return array_map(
            static fn(PartialMatchMode $mode) => [
                'label' => Craft::t('search-kit', $labels[$mode->value]),
                'value' => $mode->value,
            ],
            PartialMatchMode::cases(),
        );
    }

    /**
     * Element types travel as their own posted values rather than as array keys, so a class name
     * never has to survive being used as an input name.
     *
     * @param mixed $postedTypes
     * @param mixed $postedRows
     * @return SearchableField[]
     */
    private function resolveFields(mixed $postedTypes, mixed $postedRows): array
    {
        if (!is_array($postedTypes) || !is_array($postedRows)) {
            return [];
        }

        $indexable = SearchKit::instance()->getSearchableFields()->getIndexableElementTypes();
        $fields = [];

        foreach ($postedTypes as $group => $elementType) {
            $rows = $postedRows[$group] ?? null;

            if (!is_array($rows) || !in_array($elementType, $indexable, true)) {
                continue;
            }


            foreach ($rows as $row) {
                $handle = is_array($row) ? trim((string)($row['handle'] ?? '')) : '';

                if ($handle === '') {
                    continue;
                }

                $fields[] = new SearchableField([
                    'elementType' => (string)$elementType,
                    'handle' => $handle,
                    'weight' => max(0, (int)($row['weight'] ?? SearchableField::DEFAULT_WEIGHT)),
                    'enabled' => (bool)($row['enabled'] ?? true),
                ]);
            }
        }

        return $fields;
    }

    /**
     * @param SearchableField[] $fields
     */
    private function failure(SearchIndex $index, array $fields): null
    {
        $index->setFields($fields);
        $this->setFailFlash(Craft::t('search-kit', 'Couldn’t save search index.'));

        Craft::$app->getUrlManager()->setRouteParams(['index' => $index]);

        return null;
    }

    private function requireIndex(): SearchIndex
    {
        $indexId = (int)$this->request->getRequiredBodyParam('indexId');
        $index = SearchKit::instance()->getIndexes()->getIndexById($indexId);

        if ($index === null) {
            throw new NotFoundHttpException('Search index not found.');
        }

        return $index;
    }

    private function canManage(): bool
    {
        return Craft::$app->getUser()->checkPermission(SearchKit::PERMISSION_MANAGE);
    }

    private function canRebuild(): bool
    {
        return Craft::$app->getUser()->checkPermission(SearchKit::PERMISSION_REBUILD);
    }
}
