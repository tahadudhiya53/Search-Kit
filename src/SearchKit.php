<?php

namespace Tahadudhiya\SearchKit;

use Craft;
use craft\base\Plugin;
use craft\events\ElementEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use Tahadudhiya\SearchKit\events\SearchEvent;
use Tahadudhiya\SearchKit\gql\queries\SearchQueries;
use Tahadudhiya\SearchKit\gql\resolvers\SearchResolver;
use Tahadudhiya\SearchKit\gql\types\SearchHitType;
use Tahadudhiya\SearchKit\gql\types\SearchResultType;
use Tahadudhiya\SearchKit\services\Analytics;
use Tahadudhiya\SearchKit\services\Api;
use Tahadudhiya\SearchKit\services\ApiKeys;
use Tahadudhiya\SearchKit\services\Commerce;
use Tahadudhiya\SearchKit\services\DashboardLayouts;
use Tahadudhiya\SearchKit\services\Debugger;
use Tahadudhiya\SearchKit\services\Documents;
use Tahadudhiya\SearchKit\services\Highlighting;
use Tahadudhiya\SearchKit\services\Indexes;
use Tahadudhiya\SearchKit\services\Indexing;
use Tahadudhiya\SearchKit\services\IndexOperations;
use Tahadudhiya\SearchKit\services\Insights;
use Tahadudhiya\SearchKit\services\Intelligence;
use Tahadudhiya\SearchKit\services\Intent;
use Tahadudhiya\SearchKit\services\Normalization;
use Tahadudhiya\SearchKit\services\Providers;
use Tahadudhiya\SearchKit\services\QueryPipeline;
use Tahadudhiya\SearchKit\services\Recommendations;
use Tahadudhiya\SearchKit\services\RuleEngine;
use Tahadudhiya\SearchKit\services\Rules;
use Tahadudhiya\SearchKit\services\Search;
use Tahadudhiya\SearchKit\services\SearchableFields;
use Tahadudhiya\SearchKit\services\StopWords;
use Tahadudhiya\SearchKit\services\Suggestions;
use Tahadudhiya\SearchKit\services\Synonyms;
use Tahadudhiya\SearchKit\services\Terms;
use Tahadudhiya\SearchKit\variables\SearchKitVariable;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * SearchKit — search management and intelligence for Craft CMS.
 *
 * @property-read Analytics $analytics
 * @property-read Api $api
 * @property-read ApiKeys $apiKeys
 * @property-read Commerce $commerce
 * @property-read DashboardLayouts $dashboardLayouts
 * @property-read Debugger $debugger
 * @property-read Documents $documents
 * @property-read Highlighting $highlighting
 * @property-read IndexOperations $indexOperations
 * @property-read Indexes $indexes
 * @property-read Indexing $indexing
 * @property-read Insights $insights
 * @property-read Intelligence $intelligence
 * @property-read Intent $intent
 * @property-read Normalization $normalization
 * @property-read Providers $providers
 * @property-read QueryPipeline $queryPipeline
 * @property-read Recommendations $recommendations
 * @property-read RuleEngine $ruleEngine
 * @property-read Rules $rules
 * @property-read Search $search
 * @property-read SearchableFields $searchableFields
 * @property-read StopWords $stopWords
 * @property-read Suggestions $suggestions
 * @property-read Synonyms $synonyms
 * @property-read Terms $terms
 */
class SearchKit extends Plugin
{
    /** @var string The category SearchKit logs under. */
    public const LOG_CATEGORY = 'search-kit';

    public const PERMISSION_VIEW = 'searchKit:viewIndexes';
    public const PERMISSION_MANAGE = 'searchKit:manageIndexes';
    public const PERMISSION_REBUILD = 'searchKit:rebuildIndexes';
    public const PERMISSION_MANAGE_RULES = 'searchKit:manageRules';
    public const PERMISSION_DEBUG = 'searchKit:debugSearch';
    public const PERMISSION_VIEW_INSIGHTS = 'searchKit:viewInsights';
    public const PERMISSION_MANAGE_INSIGHTS = 'searchKit:manageInsights';
    public const PERMISSION_MANAGE_API_KEYS = 'searchKit:manageApiKeys';

    public string $schemaVersion = '1.13.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;

    /**
     * The running plugin, for the many places that reach a service through it. Null only means
     * SearchKit is not installed, which nothing calling this can be running without.
     */
    public static function instance(): self
    {
        return self::getInstance()
            ?? throw new InvalidConfigException('Search Kit is not installed or is disabled.');
    }

    public static function config(): array
    {
        return [
            'components' => [
                'analytics' => ['class' => Analytics::class],
                'api' => ['class' => Api::class],
                'apiKeys' => ['class' => ApiKeys::class],
                'commerce' => ['class' => Commerce::class],
                'dashboardLayouts' => ['class' => DashboardLayouts::class],
                'debugger' => ['class' => Debugger::class],
                'documents' => ['class' => Documents::class],
                'highlighting' => ['class' => Highlighting::class],
                'indexOperations' => ['class' => IndexOperations::class],
                'indexes' => ['class' => Indexes::class],
                'indexing' => ['class' => Indexing::class],
                'insights' => ['class' => Insights::class],
                'intelligence' => ['class' => Intelligence::class],
                'intent' => ['class' => Intent::class],
                'normalization' => ['class' => Normalization::class],
                'providers' => ['class' => Providers::class],
                'queryPipeline' => ['class' => QueryPipeline::class],
                'recommendations' => ['class' => Recommendations::class],
                'ruleEngine' => ['class' => RuleEngine::class],
                'rules' => ['class' => Rules::class],
                'search' => ['class' => Search::class],
                'searchableFields' => ['class' => SearchableFields::class],
                'stopWords' => ['class' => StopWords::class],
                'suggestions' => ['class' => Suggestions::class],
                'synonyms' => ['class' => Synonyms::class],
                'terms' => ['class' => Terms::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerContentSync();
        $this->registerSearchAnalytics();
        $this->registerTwigVariable();
        $this->registerSiteRoutes();
        $this->registerGraphql();

        // Permissions and CP routes need services Craft has not finished building yet.
        Craft::$app->onInit(function() {
            $this->registerCpRoutes();
            $this->registerPermissions();

            // Commerce has to be resolvable before it can be asked whether it is there.
            $this->getCommerce()->register();
        });
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null || !$this->canViewIndexes()) {
            return null;
        }

        $item['label'] = Craft::t('search-kit', 'Search Kit');

        // The section itself opens the dashboard, so it is not a subnav item of its own. Without
        // permission to see it, the section opens the indexes instead.
        $item['url'] = $this->canViewInsights() ? 'search-kit' : 'search-kit/indexes';
        $item['subnav'] = [
            'indexes' => ['label' => Craft::t('search-kit', 'Indexes'), 'url' => 'search-kit/indexes'],
            'rules' => ['label' => Craft::t('search-kit', 'Rules'), 'url' => 'search-kit/rules'],
            'synonyms' => ['label' => Craft::t('search-kit', 'Synonyms'), 'url' => 'search-kit/synonyms'],
        ];

        if ($this->userCan(self::PERMISSION_DEBUG)) {
            $item['subnav']['debug'] = [
                'label' => Craft::t('search-kit', 'Debugger'),
                'url' => 'search-kit/debug',
            ];
        }

        if ($this->userCan(self::PERMISSION_MANAGE_API_KEYS)) {
            $item['subnav']['api-keys'] = [
                'label' => Craft::t('search-kit', 'API keys'),
                'url' => 'search-kit/api-keys',
            ];
        }

        if ($this->canViewInsights()) {
            $item['subnav']['analytics'] = [
                'label' => Craft::t('search-kit', 'Search activity'),
                'url' => 'search-kit/analytics',
            ];

            $item['subnav']['intelligence'] = [
                'label' => Craft::t('search-kit', 'What to do next'),
                'url' => 'search-kit/intelligence',
            ];
        }

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

    /**
     * Analytics listens to search rather than search calling analytics, so a search runs exactly the
     * same whether anything is recording it or not.
     */
    private function registerSearchAnalytics(): void
    {
        Event::on(Search::class, Search::EVENT_AFTER_SEARCH, function(SearchEvent $event) {
            if ($event->result !== null) {
                $event->result->trackingToken = $this->getAnalytics()->recordSearch(
                    $event->query,
                    $event->index,
                    $event->result,
                );
            }
        });

        // Retention runs with Craft's own garbage collection, so nothing has to be scheduled.
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->getAnalytics()->prune();
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
            $event->rules['search-kit'] = 'search-kit/dashboard/index';
            $event->rules['search-kit/indexes'] = 'search-kit/indexes/index';
            $event->rules['search-kit/indexes/new'] = 'search-kit/indexes/edit';
            $event->rules['search-kit/indexes/<indexId:\d+>'] = 'search-kit/indexes/edit';
            $event->rules['search-kit/rules'] = 'search-kit/rules/index';
            $event->rules['search-kit/rules/new'] = 'search-kit/rules/edit';
            $event->rules['search-kit/rules/<ruleId:\d+>'] = 'search-kit/rules/edit';
            $event->rules['search-kit/dashboard'] = 'search-kit/dashboard/index';
            $event->rules['search-kit/debug'] = 'search-kit/debug/index';
            $event->rules['search-kit/analytics'] = 'search-kit/analytics/index';
            $event->rules['search-kit/intelligence'] = 'search-kit/intelligence/index';
            $event->rules['search-kit/synonyms'] = 'search-kit/synonyms/index';
            $event->rules['search-kit/synonyms/new'] = 'search-kit/synonyms/edit';
            $event->rules['search-kit/synonyms/<synonymId:\d+>'] = 'search-kit/synonyms/edit';
            $event->rules['search-kit/api-keys'] = 'search-kit/api-keys/index';
            $event->rules['search-kit/api-keys/new'] = 'search-kit/api-keys/edit';
            $event->rules['search-kit/api-keys/<keyId:\d+>'] = 'search-kit/api-keys/edit';
        });
    }

    /**
     * The search API's own route, so it is reached at a fixed address rather than through Craft's
     * action trigger.
     */
    private function registerSiteRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['search-kit/api/search'] = 'search-kit/api/search';
        });
    }

    /**
     * GraphQL is another way into the same search service, so it adds a query and the schema
     * components that decide which indexes a token may search.
     */
    private function registerGraphql(): void
    {
        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_TYPES, function(RegisterGqlTypesEvent $event) {
            $event->types[] = SearchHitType::class;
            $event->types[] = SearchResultType::class;
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_QUERIES, function(RegisterGqlQueriesEvent $event) {
            $event->queries = array_merge($event->queries, SearchQueries::getQueries());
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS, function(RegisterGqlSchemaComponentsEvent $event) {
            $components = [];

            foreach ($this->getIndexes()->getAllIndexes() as $index) {
                $components[SearchResolver::schemaComponent($index) . ':read'] = [
                    'label' => Craft::t('search-kit', 'Search the “{name}” index', ['name' => $index->name]),
                ];
            }

            if ($components !== []) {
                $event->queries[Craft::t('search-kit', 'Search Kit')] = $components;
            }
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => 'Search Kit',
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
                            self::PERMISSION_MANAGE_RULES => [
                                'label' => Craft::t('search-kit', 'Create, edit and delete search rules'),
                            ],
                            self::PERMISSION_DEBUG => [
                                'label' => Craft::t('search-kit', 'Run searches in the debugger'),
                            ],
                            self::PERMISSION_MANAGE_API_KEYS => [
                                'label' => Craft::t('search-kit', 'Create and revoke search API keys'),
                            ],
                            self::PERMISSION_VIEW_INSIGHTS => [
                                'label' => Craft::t('search-kit', 'View search activity'),
                                'nested' => [
                                    self::PERMISSION_MANAGE_INSIGHTS => [
                                        'label' => Craft::t('search-kit', 'Delete recorded search activity'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function canViewIndexes(): bool
    {
        return $this->userCan(self::PERMISSION_VIEW);
    }

    private function canViewInsights(): bool
    {
        return $this->userCan(self::PERMISSION_VIEW_INSIGHTS);
    }

    private function userCan(string $permission): bool
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user !== null && ($user->admin || $user->can($permission));
    }

    public function getAnalytics(): Analytics
    {
        return $this->get('analytics');
    }

    public function getApi(): Api
    {
        return $this->get('api');
    }

    public function getApiKeys(): ApiKeys
    {
        return $this->get('apiKeys');
    }

    public function getCommerce(): Commerce
    {
        return $this->get('commerce');
    }

    public function getDashboardLayouts(): DashboardLayouts
    {
        return $this->get('dashboardLayouts');
    }

    public function getDebugger(): Debugger
    {
        return $this->get('debugger');
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

    public function getInsights(): Insights
    {
        return $this->get('insights');
    }

    public function getIntelligence(): Intelligence
    {
        return $this->get('intelligence');
    }

    public function getIntent(): Intent
    {
        return $this->get('intent');
    }

    public function getProviders(): Providers
    {
        return $this->get('providers');
    }

    public function getRecommendations(): Recommendations
    {
        return $this->get('recommendations');
    }

    public function getRuleEngine(): RuleEngine
    {
        return $this->get('ruleEngine');
    }

    public function getRules(): Rules
    {
        return $this->get('rules');
    }

    public function getSearch(): Search
    {
        return $this->get('search');
    }

    public function getSearchableFields(): SearchableFields
    {
        return $this->get('searchableFields');
    }

    public function getNormalization(): Normalization
    {
        return $this->get('normalization');
    }

    public function getQueryPipeline(): QueryPipeline
    {
        return $this->get('queryPipeline');
    }

    public function getStopWords(): StopWords
    {
        return $this->get('stopWords');
    }

    public function getSuggestions(): Suggestions
    {
        return $this->get('suggestions');
    }

    public function getSynonyms(): Synonyms
    {
        return $this->get('synonyms');
    }

    public function getTerms(): Terms
    {
        return $this->get('terms');
    }
}
