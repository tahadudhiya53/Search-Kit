<?php

namespace Tahadudhiya\SearchKit\base;

use craft\base\ConfigurableComponent;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\models\ProviderStatus;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;

/**
 * Base provider. Everything a provider has not declared support for fails loudly instead of
 * silently doing nothing, so providers only implement what they can actually do.
 */
abstract class SearchProvider extends ConfigurableComponent implements SearchProviderInterface
{
    public static function capabilities(): array
    {
        return [ProviderCapability::Search];
    }

    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, static::capabilities(), true);
    }

    public function facets(SearchQuery $query, SearchIndex $index): array
    {
        throw UnsupportedCapabilityException::for(static::displayName(), ProviderCapability::Faceting);
    }

    public function indexDocument(SearchIndex $index, SearchDocument $document): void
    {
        throw UnsupportedCapabilityException::for(static::displayName(), ProviderCapability::Indexing);
    }

    public function deleteDocument(SearchIndex $index, SearchDocument $document): void
    {
        throw UnsupportedCapabilityException::for(static::displayName(), ProviderCapability::Deleting);
    }

    public function rebuild(SearchIndex $index): void
    {
        throw UnsupportedCapabilityException::for(static::displayName(), ProviderCapability::Rebuilding);
    }

    public function status(SearchIndex $index): ProviderStatus
    {
        return ProviderStatus::available();
    }

    /**
     * Nothing, until a provider says otherwise. Withholding by default is what keeps a provider
     * that reports its own request from handing its credentials to whoever can open the debugger.
     */
    public function diagnostics(array $metadata): array
    {
        return [];
    }
}
