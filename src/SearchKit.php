<?php

namespace Tahadudhiya\SearchKit;

use craft\base\Plugin;
use Tahadudhiya\SearchKit\services\Indexes;
use Tahadudhiya\SearchKit\services\Providers;
use Tahadudhiya\SearchKit\services\Search;
use Tahadudhiya\SearchKit\services\SearchableFields;

/**
 * SearchKit — search management and intelligence for Craft CMS.
 *
 * @property-read Indexes $indexes
 * @property-read Providers $providers
 * @property-read Search $search
 * @property-read SearchableFields $searchableFields
 */
class SearchKit extends Plugin
{
    /** @var string The category SearchKit logs under. */
    public const LOG_CATEGORY = 'search-kit';

    public string $schemaVersion = '1.1.0';

    public static function config(): array
    {
        return [
            'components' => [
                'indexes' => ['class' => Indexes::class],
                'providers' => ['class' => Providers::class],
                'search' => ['class' => Search::class],
                'searchableFields' => ['class' => SearchableFields::class],
            ],
        ];
    }

    public function getIndexes(): Indexes
    {
        return $this->get('indexes');
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
