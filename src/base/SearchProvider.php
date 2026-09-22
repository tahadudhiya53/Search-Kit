<?php

namespace Tahadudhiya\SearchKit\base;

use Craft;
use craft\base\ConfigurableComponent;
use craft\base\ElementInterface;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\models\ProviderStatus;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\SearchKit;

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

    /**
     * Accepts a class name or Craft's own reference handle, so templates need not name classes.
     *
     * @param string[] $elementTypes
     * @throws InvalidQueryException
     */
    protected function resolveElementType(string $value, array $elementTypes): string
    {
        foreach ($elementTypes as $elementType) {
            /** @var class-string<ElementInterface> $elementType */
            if ($value === $elementType || $value === $elementType::refHandle()) {
                return $elementType;
            }
        }

        throw new InvalidQueryException(
            "“{$value}” is not an element type this search index covers.",
            ['filters' => ["“{$value}” is not an element type this search index covers."]],
        );
    }

    /**
     * What every provider has to be able to assume about a document before writing it: that it
     * belongs to a site the index covers, and that the index searches something the element holds.
     *
     * @throws ProviderException
     */
    protected function assertDocumentIsIndexable(SearchIndex $index, SearchDocument $document): void
    {
        if (!$index->coversSite($document->siteId)) {
            throw new ProviderException("The “{$index->handle}” search index does not cover this element's site.");
        }

        if ($document->isEmpty()) {
            Craft::error(
                "The “{$index->handle}” search index has no enabled searchable fields for {$document->elementType}",
                SearchKit::LOG_CATEGORY,
            );
            throw new ProviderException("The “{$index->handle}” search index is not configured for this element type.");
        }
    }
}
