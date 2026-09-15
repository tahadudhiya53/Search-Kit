<?php

namespace Tahadudhiya\SearchKit\base;

use craft\base\ComponentInterface;
use craft\base\ElementInterface;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\models\ProviderStatus;
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
     * Adds or replaces one element in the index.
     *
     * @throws \Tahadudhiya\SearchKit\errors\ProviderException
     */
    public function indexElement(SearchIndex $index, ElementInterface $element): void;

    /**
     * @throws \Tahadudhiya\SearchKit\errors\ProviderException
     */
    public function deleteElement(SearchIndex $index, ElementInterface $element): void;

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
