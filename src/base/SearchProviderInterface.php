<?php

namespace Tahadudhiya\SearchKit\base;

use craft\base\ConfigurableComponentInterface;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\models\ProviderStatus;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;

/**
 * The single seam between SearchKit and a search engine. Core talks to this, never to an engine.
 * Configurable, because a provider reaching an external service has to be told how to reach it.
 */
interface SearchProviderInterface extends ConfigurableComponentInterface
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
     * How the whole result set this query matches divides up by each field it asked to be counted
     * by. It describes the search rather than the page, so nothing here reads the window.
     *
     * @return \Tahadudhiya\SearchKit\models\Facet[]
     * @throws \Tahadudhiya\SearchKit\errors\ProviderException
     */
    public function facets(SearchQuery $query, SearchIndex $index): array;

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

    /**
     * The parts of what this provider reported about a search that may be shown to a developer.
     * Nothing is shown unless a provider names it here, so a credential, a header or a setting can
     * never reach the debugger by being put in a result's metadata.
     *
     * @param array<string,mixed> $metadata What the provider reported on the result.
     * @return array<string,mixed>
     */
    public function diagnostics(array $metadata): array;
}
