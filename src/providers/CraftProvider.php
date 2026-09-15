<?php

namespace Tahadudhiya\SearchKit\providers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use Tahadudhiya\SearchKit\base\SearchProvider;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;

/**
 * Serves searches from Craft's own search index, so SearchKit is useful without external services.
 */
class CraftProvider extends SearchProvider
{
    public static function displayName(): string
    {
        return 'Craft';
    }

    /**
     * Craft scores results itself: it has no per-field weighting, highlighting, or arbitrary
     * filtering, so SearchKit rejects queries asking for those rather than quietly ignoring them.
     */
    public static function capabilities(): array
    {
        return [
            ProviderCapability::Search,
            ProviderCapability::Indexing,
        ];
    }

    public function search(SearchQuery $query, SearchIndex $index): SearchResult
    {
        $elementTypes = $index->getElementTypes();

        if ($elementTypes === []) {
            throw new ProviderException("The “{$index->handle}” search index has no enabled searchable fields.");
        }

        $singleElementType = count($elementTypes) === 1;
        $hits = [];
        $total = 0;

        foreach ($elementTypes as $elementType) {
            try {
                $elementQuery = $this->createElementQuery($elementType, $query, $index);
                $total += (int)$elementQuery->count();

                $paginated = (clone $elementQuery)
                    ->limit($singleElementType ? $query->limit : $query->offset + $query->limit);

                if ($singleElementType) {
                    $paginated->offset($query->offset);
                }

                $elements = $paginated->all();
            } catch (Throwable $e) {
                Craft::error("Craft could not search {$elementType}: {$e->getMessage()}", SearchKit::LOG_CATEGORY);
                throw new ProviderException("Craft could not search {$elementType}.", 0, $e);
            }

            foreach ($elements as $element) {
                $hits[] = $this->createHit($element, $elementType);
            }
        }

        if (!$singleElementType) {
            usort($hits, static fn(SearchHit $a, SearchHit $b) => $b->score <=> $a->score);
            $hits = array_slice($hits, $query->offset, $query->limit);
        }

        return new SearchResult([
            'hits' => $hits,
            'total' => $total,
            'limit' => $query->limit,
            'offset' => $query->offset,
            'indexHandle' => $index->handle,
            'provider' => static::class,
            'metadata' => [
                'elementTypes' => $elementTypes,
            ],
        ]);
    }

    /**
     * Craft reads a null field list as “index every field”, so an index that configures no fields
     * for this element type is a configuration error rather than an implicit index-everything.
     */
    public function indexElement(SearchIndex $index, ElementInterface $element): void
    {
        $siteId = $element->siteId;
        $inScope = $siteId !== null ? $index->coversSite($siteId) : $index->coversAllSites();

        if (!$inScope) {
            throw new ProviderException("The “{$index->handle}” search index does not cover this element's site.");
        }

        $handles = array_keys($index->getFieldWeights($element::class));

        if ($handles === []) {
            Craft::error(
                "The “{$index->handle}” search index has no enabled searchable fields for " . $element::class,
                SearchKit::LOG_CATEGORY,
            );
            throw new ProviderException("The “{$index->handle}” search index is not configured for this element type.");
        }

        try {
            $indexed = Craft::$app->getSearch()->indexElementAttributes($element, $handles);
        } catch (Throwable $e) {
            Craft::error("Craft could not index element {$element->id}: {$e->getMessage()}", SearchKit::LOG_CATEGORY);
            throw new ProviderException("Craft could not index element {$element->id}.", 0, $e);
        }

        if (!$indexed) {
            throw new ProviderException("Craft reported a failure indexing element {$element->id}.");
        }
    }

    /**
     * @param class-string<ElementInterface> $elementType
     */
    private function createElementQuery(string $elementType, SearchQuery $query, SearchIndex $index): ElementQueryInterface
    {
        // A null site on both the query and the index means every site, which is Craft's `'*'`.
        $siteId = $query->siteId ?? $index->siteId;

        $elementQuery = $elementType::find()
            ->search($query->getNormalizedText())
            ->siteId($siteId ?? '*')
            ->orderBy('score');

        if ($query->status !== null) {
            $elementQuery->status($query->status);
        }

        return $elementQuery;
    }

    private function createHit(ElementInterface $element, string $elementType): SearchHit
    {
        return new SearchHit([
            'elementId' => (int)$element->id,
            'siteId' => $element->siteId,
            'elementType' => $elementType,
            'score' => (float)($element->searchScore ?? 0),
            'element' => $element,
        ]);
    }
}
