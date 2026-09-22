<?php

namespace Tahadudhiya\SearchKit\services;

use Tahadudhiya\SearchKit\errors\IndexNotFoundException;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\models\ApiKey;
use Tahadudhiya\SearchKit\models\Facet;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\SearchKit;
use yii\base\Component;

/**
 * What the REST API makes of a search: it vets what a client asked for, runs it through the same
 * search service PHP and Twig use, and returns the result as plain data.
 */
class Api extends Component
{
    /** @var string[] Parameters the API reads itself; everything else is a search parameter. */
    private const RESERVED = ['index', 'q'];

    /**
     * Runs a search for an API client. The key decides which indexes exist as far as this request
     * is concerned; everything else is decided by the search itself.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function search(ApiKey $key, array $params): array
    {
        $handle = $this->handle($params);
        $index = SearchKit::instance()->getSearch()->resolveIndex($handle);

        // An index this key may not search is reported as though it were not there, so a key can
        // never be used to find out which indexes exist.
        if ($index->id === null || !$key->coversIndex((int)$index->id)) {
            throw new IndexNotFoundException("No search index exists with the handle “{$handle}”.");
        }

        $query = SearchQuery::create($handle, $this->text($params), $this->searchParams($params));

        return $this->present(SearchKit::instance()->getSearch()->search($query));
    }

    /**
     * @param array<string,mixed> $params
     */
    private function handle(array $params): string
    {
        $handle = $params['index'] ?? null;

        if (!is_string($handle) || trim($handle) === '') {
            throw new InvalidQueryException(
                'A search needs an index.',
                ['index' => ['“index” must name a search index.']],
            );
        }

        return trim($handle);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function text(array $params): string
    {
        $text = $params['q'] ?? '';

        if (!is_string($text)) {
            throw new InvalidQueryException(
                'A search needs something to look for.',
                ['q' => ['“q” must be text.']],
            );
        }

        return $text;
    }

    /**
     * Everything else is handed to the query as it stands, so the API accepts exactly what PHP and
     * Twig accept and rejects the rest in the same words.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function searchParams(array $params): array
    {
        // The API is answered anonymously, and only published content is searchable anonymously.
        // Asking for a status would always be refused, so it is refused here where it can be explained.
        if (array_key_exists('status', $params)) {
            throw new InvalidQueryException(
                'The API searches published content only.',
                ['status' => ['“status” cannot be asked for through the API.']],
            );
        }

        $searchParams = array_diff_key($params, array_flip(self::RESERVED));

        if (array_key_exists('highlight', $searchParams)) {
            $searchParams['highlight'] = $this->boolean($searchParams['highlight']);
        }

        return $searchParams;
    }

    /**
     * Request parameters arrive as text, so the words a query string can carry a boolean in are
     * read as one. Anything else is a mistake rather than something to guess at.
     */
    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (is_string($value) ? strtolower($value) : $value) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new InvalidQueryException(
                'A search parameter is not valid.',
                ['highlight' => ['“highlight” must be true or false.']],
            ),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function present(SearchResult $result): array
    {
        return [
            'index' => $result->indexHandle,
            'query' => $result->parsedQuery->raw ?? '',
            'correctedQuery' => $result->correctedText,
            'suggestions' => $result->suggestions,
            'redirect' => $result->redirect,
            'total' => $result->total,
            'limit' => $result->limit,
            'offset' => $result->offset,
            'page' => $result->getPage(),
            'pageCount' => $result->getPageCount(),
            'hasNextPage' => $result->getHasNextPage(),
            'hasPreviousPage' => $result->getHasPreviousPage(),
            'executionTime' => round($result->executionTime, 3),
            'trackingToken' => $result->trackingToken,
            'facets' => array_map(
                static fn(Facet $facet) => ['field' => $facet->field, 'values' => $facet->getValues()],
                $result->facets,
            ),
            'hits' => array_map(fn(SearchHit $hit) => $this->presentHit($hit), $result->hits),
        ];
    }

    /**
     * A hit is reported as what it is and where it ranked. Content beyond a title and a URL is the
     * element's to give out, not the search API's.
     *
     * @return array<string,mixed>
     */
    private function presentHit(SearchHit $hit): array
    {
        return [
            'elementId' => $hit->elementId,
            'siteId' => $hit->siteId,
            'elementType' => $hit->getElementTypeHandle(),
            'title' => $hit->element?->getUiLabel(),
            'url' => $hit->element?->getUrl(),
            'score' => $hit->score,
            'scoreAdjustment' => $hit->scoreAdjustment,
            'finalScore' => $hit->getFinalScore(),
            'pinned' => $hit->pinned,
            'promoted' => $hit->promoted,
            'matchedFields' => $hit->matchedFields,
            'snippets' => $hit->snippets,
            'highlights' => $hit->highlights,
        ];
    }
}
