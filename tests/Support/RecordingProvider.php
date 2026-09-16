<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use Tahadudhiya\SearchKit\base\SearchProvider;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Throwable;

/**
 * A fully capable provider that records what it was asked to do. State is static because the
 * indexing service constructs its own provider instance from the index's configuration.
 */
class RecordingProvider extends SearchProvider
{
    /** @var SearchDocument[] */
    public static array $indexed = [];

    /** @var SearchDocument[] */
    public static array $deleted = [];

    public static int $rebuilds = 0;

    /** @var Throwable|null Thrown instead of indexing, to exercise failure handling. */
    public static ?Throwable $indexingFailure = null;

    /** @var int[] Element IDs that should fail; null covers every element. */
    public static ?array $failingElementIds = null;

    /** @var bool Whether this provider can discard and recreate its index. */
    public static bool $rebuildingSupported = true;

    /** @var callable|null Run on every indexed document, to stand in for a concurrent change. */
    public static $onIndex = null;

    public static function reset(): void
    {
        self::$indexed = [];
        self::$deleted = [];
        self::$rebuilds = 0;
        self::$indexingFailure = null;
        self::$failingElementIds = null;
        self::$rebuildingSupported = true;
        self::$onIndex = null;
    }

    public static function displayName(): string
    {
        return 'Recording';
    }

    public static function capabilities(): array
    {
        $capabilities = [
            ProviderCapability::Search,
            ProviderCapability::Indexing,
            ProviderCapability::Deleting,
            ProviderCapability::FieldWeighting,
        ];

        if (self::$rebuildingSupported) {
            $capabilities[] = ProviderCapability::Rebuilding;
        }

        return $capabilities;
    }

    public function search(SearchQuery $query, SearchIndex $index): SearchResult
    {
        return new SearchResult(['indexHandle' => $index->handle]);
    }

    public function indexDocument(SearchIndex $index, SearchDocument $document): void
    {
        if (self::$indexingFailure !== null && self::coversElement($document->elementId)) {
            throw self::$indexingFailure;
        }

        self::$indexed[] = $document;

        if (self::$onIndex !== null) {
            (self::$onIndex)($document);
        }
    }

    private static function coversElement(int $elementId): bool
    {
        return self::$failingElementIds === null || in_array($elementId, self::$failingElementIds, true);
    }

    public function deleteDocument(SearchIndex $index, SearchDocument $document): void
    {
        self::$deleted[] = $document;
    }

    public function rebuild(SearchIndex $index): void
    {
        self::$rebuilds++;
    }
}
