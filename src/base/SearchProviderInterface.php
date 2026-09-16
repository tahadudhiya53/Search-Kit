<?php

namespace Tahadudhiya\SearchKit\base;

use craft\base\ComponentInterface;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\models\ProviderStatus;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;

/**
 * The single seam between SearchKit and a search engine. Core talks to this, never to an engine.
 */
interface SearchProviderInterface extends ComponentInterface
{
    /**
     * What this provider can do. Core checks this instead of branching on the provider's identity.
     *
     * @return ProviderCapability[]
     */
    public static function capabilities(): array;

    public function supports(ProviderCapability $capability): bool;

    /**
     * @throws \Tahadudhiya\SearchKit\errors\ProviderException if the search cannot be executed.
     */
    public function search(SearchQuery $query, SearchIndex $index): SearchResult;

    /**
     * Adds or replaces one document in the index. Documents are built for the provider, so no
     * provider ever has to know how a Craft element stores its values.
     *
     * @throws \Tahadudhiya\SearchKit\errors\ProviderException
     */
    public function indexDocument(SearchIndex $index, SearchDocument $document): void;

    /**
     * Removes one document. The element it described may already be gone, so only its identity
     * is guaranteed to be present.
     *
     * @throws \Tahadudhiya\SearchKit\errors\ProviderException
     */
    public function deleteDocument(SearchIndex $index, SearchDocument $document): void;

    /**
     * Discards and recreates the provider-side index.
     *
     * @throws \Tahadudhiya\SearchKit\errors\ProviderException
     */
    public function rebuild(SearchIndex $index): void;

    /**
     * Whether the provider can currently serve this index, for reporting to administrators.
     */
    public function status(SearchIndex $index): ProviderStatus;
}
