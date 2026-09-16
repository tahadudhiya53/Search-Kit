<?php

namespace Tahadudhiya\SearchKit;

use Craft;
use craft\base\Plugin;
use craft\events\ElementEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use Tahadudhiya\SearchKit\services\Documents;
use Tahadudhiya\SearchKit\services\Highlighting;
use Tahadudhiya\SearchKit\services\Indexes;
use Tahadudhiya\SearchKit\services\Indexing;
use Tahadudhiya\SearchKit\services\IndexOperations;
use Tahadudhiya\SearchKit\services\Providers;
use Tahadudhiya\SearchKit\services\Search;
use Tahadudhiya\SearchKit\services\SearchableFields;
use Tahadudhiya\SearchKit\variables\SearchKitVariable;
use yii\base\Event;

/**
 * SearchKit — search management and intelligence for Craft CMS.
 *
 * @property-read Documents $documents
 * @property-read Highlighting $highlighting
 * @property-read IndexOperations $indexOperations
 * @property-read Indexes $indexes
 * @property-read Indexing $indexing
 * @property-read Providers $providers
 * @property-read Search $search
 * @property-read SearchableFields $searchableFields
 */
class SearchKit extends Plugin
{
    /** @var string The category SearchKit logs under. */
    public const LOG_CATEGORY = 'search-kit';

    public const PERMISSION_VIEW = 'searchKit:viewIndexes';
    public const PERMISSION_MANAGE = 'searchKit:manageIndexes';
    public const PERMISSION_REBUILD = 'searchKit:rebuildIndexes';

    public string $schemaVersion = '1.5.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;

    public static function config(): array
    {
        return [
            'components' => [
                'documents' => ['class' => Documents::class],
                'highlighting' => ['class' => Highlighting::class],
                'indexOperations' => ['class' => IndexOperations::class],
                'indexes' => ['class' => Indexes::class],
                'indexing' => ['class' => Indexing::class],
                'providers' => ['class' => Providers::class],
                'search' => ['class' => Search::class],
                'searchableFields' => ['class' => SearchableFields::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerContentSync();
        $this->registerTwigVariable();

        // Permissions and CP routes need services Craft has not finished building yet.
        Craft::$app->onInit(function() {
            $this->registerCpRoutes();
            $this->registerPermissions();
        });
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null || !$this->canViewIndexes()) {
            return null;
        }

        $item['label'] = Craft::t('search-kit', 'SearchKit');
        $item['url'] = 'search-kit';

        return $item;
    }

    /**
     * Craft's element events are the only content signal SearchKit listens to, so indexes follow
     * every save, delete and restore however it was made.
     */
    private function registerContentSync(): void
    {
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
            $this->getIndexing()->handleElementSave($event->element);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function(ElementEvent $event) {
            $this->getIndexing()->handleElementDelete($event->element);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, function(ElementEvent $event) {
            $this->getIndexing()->handleElementRestore($event->element);
        });
    }

    private function registerTwigVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('searchKit', SearchKitVariable::class);
        });
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['search-kit'] = 'search-kit/indexes/index';
            $event->rules['search-kit/indexes/new'] = 'search-kit/indexes/edit';
            $event->rules['search-kit/indexes/<indexId:\d+>'] = 'search-kit/indexes/edit';
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => 'SearchKit',
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('search-kit', 'View search indexes'),
                        'nested' => [
                            self::PERMISSION_MANAGE => [
                                'label' => Craft::t('search-kit', 'Create, edit and delete search indexes'),
                            ],
                            self::PERMISSION_REBUILD => [
                                'label' => Craft::t('search-kit', 'Rebuild and retry search indexing'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function canViewIndexes(): bool
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user !== null && ($user->admin || $user->can(self::PERMISSION_VIEW));
    }

    public function getDocuments(): Documents
    {
        return $this->get('documents');
    }

    public function getHighlighting(): Highlighting
    {
        return $this->get('highlighting');
    }

    public function getIndexOperations(): IndexOperations
    {
        return $this->get('indexOperations');
    }

    public function getIndexes(): Indexes
    {
        return $this->get('indexes');
    }

    public function getIndexing(): Indexing
    {
        return $this->get('indexing');
    }

    public function getProviders(): Providers
    {
        return $this->get('providers');
    }

    public function getSearch(): Search
    {
        return $this->get('search');
    }

    public function getSearchableFields(): SearchableFields
    {
        return $this->get('searchableFields');
    }
}
