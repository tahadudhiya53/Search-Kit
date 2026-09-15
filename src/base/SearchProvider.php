<?php

namespace Tahadudhiya\SearchKit\base;

use craft\base\Component;
use craft\base\ElementInterface;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\models\ProviderStatus;
use Tahadudhiya\SearchKit\models\SearchIndex;

/**
 * Base provider. Everything a provider has not declared support for fails loudly instead of
 * silently doing nothing, so providers only implement what they can actually do.
 */
abstract class SearchProvider extends Component implements SearchProviderInterface
{
    public static function capabilities(): array
    {
        return [ProviderCapability::Search];
    }

    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, static::capabilities(), true);
    }

    public function indexElement(SearchIndex $index, ElementInterface $element): void
    {
        throw UnsupportedCapabilityException::for(static::displayName(), ProviderCapability::Indexing);
    }

    public function deleteElement(SearchIndex $index, ElementInterface $element): void
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
}
