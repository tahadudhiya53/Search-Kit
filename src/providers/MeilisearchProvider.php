<?php

namespace Tahadudhiya\SearchKit\providers;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\App;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\web\View;
use Tahadudhiya\SearchKit\base\SearchProvider;
use Tahadudhiya\SearchKit\enums\FilterOperator;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\enums\SortDirection;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\models\Facet;
use Tahadudhiya\SearchKit\models\ParsedQuery;
use Tahadudhiya\SearchKit\models\ProviderStatus;
use Tahadudhiya\SearchKit\models\QueryTerm;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchFilter;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Throwable;

/**
 * Serves searches from a Meilisearch server. One SearchKit index maps to one Meilisearch index;
 * content lives under a `fields` object, so a field handle can never collide with the identity
 * attributes SearchKit needs back.
 */
class MeilisearchProvider extends SearchProvider
{
    /** @var string Where a document's searchable content lives, away from its identity. */
    public const CONTENT = 'fields';

    /** @var string The document attribute holding the Craft element's ID. */
    public const ATTRIBUTE_ELEMENT_ID = 'elementId';

    public const ATTRIBUTE_SITE_ID = 'siteId';
    public const ATTRIBUTE_ELEMENT_TYPE = 'elementType';

    /** @var string[] Identity attributes a filter may name, on top of the index's own fields. */
    private const NUMERIC_ATTRIBUTES = [self::ATTRIBUTE_ELEMENT_ID, self::ATTRIBUTE_SITE_ID];

    /** @var string What a filter calls the element ID, matching the criteria Craft's own provider takes. */
    private const FILTER_ELEMENT_ID = 'id';

    /** @var string[] The only things this provider reports that may be shown to a developer. */
    private const DIAGNOSTICS = ['meilisearchIndex', 'meilisearchQuery', 'filter', 'sort'];

    /**
     * @var string Wraps a match in what Meilisearch formats. Control characters, because the
     * excerpt is escaped before these become tags and content must not be able to forge one.
     */
    private const HIGHLIGHT_OPEN = "\x02";

    private const HIGHLIGHT_CLOSE = "\x03";

    /** @var int Roughly how many characters one cropped word accounts for. */
    private const CHARACTERS_PER_WORD = 6;

    /** @var int Fewer words than this is not a readable excerpt. */
    private const MIN_CROP_WORDS = 10;

    /**
     * @var string[] The two-letter locales Meilisearch analyses content in, as its own settings API
     * lists them. A site in any other language is simply not declared, rather than failing a rebuild.
     */
    private const LOCALES = [
        'af', 'ak', 'am', 'ar', 'az', 'be', 'bn', 'bg', 'ca', 'cs', 'da', 'de', 'el', 'en', 'eo',
        'et', 'fi', 'fr', 'gu', 'he', 'hi', 'hr', 'hu', 'hy', 'id', 'it', 'jv', 'ja', 'kn', 'ka',
        'km', 'ko', 'la', 'lv', 'lt', 'ml', 'mr', 'mk', 'my', 'ne', 'nl', 'nb', 'or', 'pa', 'fa',
        'pl', 'pt', 'ro', 'ru', 'si', 'sk', 'sl', 'sn', 'es', 'sr', 'sv', 'ta', 'te', 'tl', 'th',
        'tk', 'tr', 'uk', 'ur', 'uz', 'vi', 'yi', 'zh', 'zu',
    ];

    /** @var string The Meilisearch server's address. An environment variable is the usual answer. */
    public string $url = '';

    /**
     * @var string The API key, which must name an environment variable rather than hold the key
     * itself: the control panel and the database only ever see the name.
     */
    public string $apiKey = '';

    /** @var string Put in front of every index name, so two environments can share one server. */
    public string $indexPrefix = '';

    private ?MeilisearchClient $_client = null;

    /** @var array<string,true> Index names already known to exist, so one process asks once. */
    private array $_ensured = [];

    public static function displayName(): string
    {
        return 'Meilisearch';
    }

    /**
     * Meilisearch tolerates typos itself and highlights what it matched, and it ranks earlier
     * searchable attributes above later ones — which is how a field weight is expressed here.
     * It has no `OR` between terms and no per-term partial matching, so neither is claimed.
     */
    public static function capabilities(): array
    {
        return [
            ProviderCapability::Search,
            ProviderCapability::Indexing,
            ProviderCapability::Deleting,
            ProviderCapability::Rebuilding,
            ProviderCapability::Filtering,
            ProviderCapability::Sorting,
            ProviderCapability::Faceting,
            ProviderCapability::Highlighting,
            ProviderCapability::FieldWeighting,
            ProviderCapability::PhraseMatching,
            ProviderCapability::TermExclusion,
            ProviderCapability::TypoTolerance,
            ProviderCapability::ResultExclusion,
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['url'], 'required'],
            [['url', 'apiKey', 'indexPrefix'], 'string', 'max' => 255],
            [['url'], 'validateUrl'],
            [['apiKey'], 'validateApiKey'],
            [['indexPrefix'], 'match', 'pattern' => '/^[A-Za-z0-9_-]*$/',
                'message' => 'A prefix may only contain letters, numbers, underscores and hyphens.', ],
        ];
    }

    public function validateUrl(string $attribute): void
    {
        $url = $this->resolved($this->url);

        if ($url === null) {
            $this->addError($attribute, "No environment variable named “{$this->url}” is set.");
            return;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url)) {
            $this->addError($attribute, 'The Meilisearch address must be an http or https URL.');
            return;
        }

        // A credential written into the address would be stored, shown back and sent on every
        // request. The API key setting is the one place a secret belongs, and it names a variable.
        $parts = parse_url($url);

        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            $this->addError(
                $attribute,
                'The Meilisearch address must not carry a username or password. Use the API key setting instead.',
            );
        }
    }

    /**
     * A key typed into the control panel would be stored in the database and shown back to whoever
     * can open the index. Naming an environment variable is the only form accepted.
     */
    public function validateApiKey(string $attribute): void
    {
        if ($this->apiKey === '') {
            return;
        }

        if (!preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $this->apiKey)) {
            $this->addError($attribute, 'The API key must name an environment variable, written as $VARIABLE_NAME.');
            return;
        }

        if ($this->resolved($this->apiKey) === null) {
            $this->addError($attribute, "No environment variable named “{$this->apiKey}” is set.");
        }
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('search-kit/providers/_meilisearch', [
            'provider' => $this,
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * What Meilisearch was asked, which is the query, the constraints and the ordering. The address
     * and the key are never among them.
     */
    public function diagnostics(array $metadata): array
    {
        $safe = [];

        foreach (self::DIAGNOSTICS as $key) {
            $value = $metadata[$key] ?? null;

            if (is_string($value) || is_array($value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    public function search(SearchQuery $query, SearchIndex $index): SearchResult
    {
        $elementTypes = $index->getElementTypes();

        if ($elementTypes === []) {
            throw new ProviderException("The “{$index->handle}” search index has no enabled searchable fields.");
        }

        $filters = $this->filterExpressions($query, $index, $elementTypes);
        $sort = $this->sortExpressions($query, $index);
        [$page, $hitsPerPage, $discard] = $this->window($query);

        $body = array_filter([
            'q' => $this->queryString($query->getParsedQuery()),
            'page' => $page,
            'hitsPerPage' => $hitsPerPage,
            'filter' => $filters !== [] ? $filters : null,
            'sort' => $sort !== [] ? $sort : null,
            'matchingStrategy' => 'all',
            'showRankingScore' => true,
            'attributesToRetrieve' => [
                self::ATTRIBUTE_ELEMENT_ID,
                self::ATTRIBUTE_SITE_ID,
                self::ATTRIBUTE_ELEMENT_TYPE,
            ],
        ], static fn(mixed $value) => $value !== null);

        if ($query->highlight) {
            $body += $this->highlightingCriteria($query);
        }

        $response = $this->getClient()->search($this->indexUid($index), $body);
        $this->assertAnswersASearch($response);

        $hits = array_slice($this->readHits($response, $query), $discard, $query->limit);

        return new SearchResult([
            'hits' => $hits,
            'total' => (int)$response['totalHits'],
            'limit' => $query->limit,
            'offset' => $query->offset,
            'indexHandle' => $index->handle,
            'provider' => static::class,
            'metadata' => [
                'meilisearchIndex' => $this->indexUid($index),
                'meilisearchQuery' => $body['q'],
                'filter' => $filters,
                'sort' => $sort,
            ],
        ]);
    }

    /**
     * Meilisearch counts the values of an attribute over the whole result set, which is what a
     * facet is. Nothing is returned with it: the results themselves were asked for separately.
     *
     * @return Facet[]
     */
    public function facets(SearchQuery $query, SearchIndex $index): array
    {
        $elementTypes = $index->getElementTypes();

        if ($elementTypes === []) {
            throw new ProviderException("The “{$index->handle}” search index has no enabled searchable fields.");
        }

        $attributes = [];

        foreach ($query->getFacets() as $field) {
            $attributes[$field] = $this->filterAttribute($field, $index);
        }

        $response = $this->getClient()->search($this->indexUid($index), [
            'q' => $this->queryString($query->getParsedQuery()),
            // No results, only their counts: the results themselves were asked for separately.
            'page' => 1,
            'hitsPerPage' => 0,
            'filter' => $this->filterExpressions($query, $index, $elementTypes),
            'facets' => array_values($attributes),
            'matchingStrategy' => 'all',
        ]);

        $this->assertAnswersASearch($response);

        $distribution = (array)($response['facetDistribution'] ?? []);

        return array_map(
            static fn(string $field) => Facet::make($field, (array)($distribution[$attributes[$field]] ?? [])),
            $query->getFacets(),
        );
    }

    /**
     * A search has to come back as a list of results and a count of them. Anything else is a
     * failure: read leniently, a response missing both would be indistinguishable from an index
     * that legitimately holds nothing.
     *
     * @param array<string,mixed> $response
     * @throws ProviderException
     */
    private function assertAnswersASearch(array $response): void
    {
        if (!isset($response['hits']) || !is_array($response['hits'])) {
            throw new ProviderException('Meilisearch answered a search without a list of results.');
        }

        if (!isset($response['totalHits']) || !is_int($response['totalHits'])) {
            throw new ProviderException('Meilisearch answered a search without a count of its results.');
        }
    }

    public function indexDocument(SearchIndex $index, SearchDocument $document): void
    {
        if (!$index->coversSite($document->siteId)) {
            throw new ProviderException("The “{$index->handle}” search index does not cover this element's site.");
        }

        if ($document->isEmpty()) {
            throw new ProviderException("The “{$index->handle}” search index is not configured for this element type.");
        }

        $this->ensureIndex($index);
        $client = $this->getClient();

        // Waited on, because SearchKit settles the indexing operation when this returns: a task
        // that failed afterwards would leave the index reported as holding content it never took.
        $client->waitForTask($client->addDocuments($this->indexUid($index), [[
            'id' => $this->documentId($document->elementId, $document->siteId),
            self::ATTRIBUTE_ELEMENT_ID => $document->elementId,
            self::ATTRIBUTE_SITE_ID => $document->siteId,
            self::ATTRIBUTE_ELEMENT_TYPE => $document->elementType,
            self::CONTENT => $document->getFields(),
        ]]));
    }

    public function deleteDocument(SearchIndex $index, SearchDocument $document): void
    {
        // Nothing to delete from an index that was never created, and creating one to empty it
        // would be a strange thing for a deletion to do.
        if (!$this->indexIsPresent($index)) {
            return;
        }

        $client = $this->getClient();

        // Waited on for the same reason an indexing write is: a deletion SearchKit has released
        // will never be retried, so it has to have actually happened.
        $client->waitForTask($client->deleteDocument(
            $this->indexUid($index),
            $this->documentId($document->elementId, $document->siteId),
        ));
    }

    /**
     * Discards the Meilisearch index and recreates it against the current configuration, which is
     * the only moment the searchable, filterable and sortable attributes are settled.
     */
    public function rebuild(SearchIndex $index): void
    {
        $client = $this->getClient();
        $uid = $this->indexUid($index);

        if ($client->indexExists($uid)) {
            // Anything still queued would otherwise be applied to whichever index exists when
            // Meilisearch reaches it, which after this is the newly built one.
            $client->waitForIndexTasks($uid);
            $client->waitForTask($client->deleteIndex($uid));
        }

        $this->createIndex($index);
    }

    public function status(SearchIndex $index): ProviderStatus
    {
        $uid = $this->indexUid($index);

        try {
            if (!$this->getClient()->health()) {
                return ProviderStatus::unavailable('The Meilisearch server is not reporting itself as available.');
            }

            if (!$this->getClient()->indexExists($uid)) {
                return ProviderStatus::unavailable(
                    "Meilisearch has no “{$uid}” index yet. Rebuild this index to create it.",
                );
            }
        } catch (Throwable $e) {
            // Already safe to show: the client keeps the detail in the log.
            return ProviderStatus::unavailable($e instanceof ProviderException
                ? $e->getMessage()
                : 'The Meilisearch server could not be reached.');
        }

        return ProviderStatus::available(null, ['index' => $uid]);
    }

    /**
     * The Meilisearch index behind a SearchKit index. The prefix is what lets two environments
     * point at one server without writing over each other.
     */
    public function indexUid(SearchIndex $index): string
    {
        return $this->resolved($this->indexPrefix, '') . $index->handle;
    }

    public function setClient(MeilisearchClient $client): void
    {
        $this->_client = $client;
    }

    public function getClient(): MeilisearchClient
    {
        return $this->_client ??= new MeilisearchClient(
            (string)$this->resolved($this->url, ''),
            (string)$this->resolved($this->apiKey, ''),
        );
    }

    /**
     * Creates the index and applies the attribute configuration the current searchable fields ask
     * for. Waited on, so nothing is written to an index that is still being configured.
     */
    private function createIndex(SearchIndex $index): void
    {
        $client = $this->getClient();
        $uid = $this->indexUid($index);

        $client->waitForTask($client->createIndex($uid, 'id'));
        $client->waitForTask($client->updateSettings($uid, $this->indexSettings($index)));

        $this->_ensured[$uid] = true;
    }

    /**
     * @return array<string,mixed>
     */
    private function indexSettings(SearchIndex $index): array
    {
        $searchable = $this->weightedAttributes($index);
        $identity = [self::ATTRIBUTE_ELEMENT_ID, self::ATTRIBUTE_SITE_ID, self::ATTRIBUTE_ELEMENT_TYPE];

        $locales = $this->locales($index);

        return array_filter([
            // Content is analysed in the languages the index's sites are written in, so a word is
            // tokenized and stemmed the way that language works rather than as plain text.
            'localizedAttributes' => $locales !== []
                ? [['attributePatterns' => [self::CONTENT . '.*'], 'locales' => $locales]]
                : null,
            // Meilisearch ranks an earlier searchable attribute above a later one, so the heaviest
            // field is listed first. That ordering is the whole of what a weight means here.
            'searchableAttributes' => $searchable,
            'filterableAttributes' => [...$identity, ...$searchable],
            'sortableAttributes' => [...self::NUMERIC_ATTRIBUTES, ...$searchable],
            // `sort` comes first, because SearchKit's ordering is an instruction rather than a
            // tiebreaker; left where Meilisearch puts it, relevance would outrank it.
            'rankingRules' => ['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness'],
        ], static fn(mixed $value) => $value !== null);
    }

    /**
     * The languages this index's content is written in, as Meilisearch names them. Craft writes a
     * language as `de-CH`; Meilisearch analyses by language alone, and one it does not know is left
     * undeclared rather than failing the rebuild that would have declared it.
     *
     * @return string[]
     */
    private function locales(SearchIndex $index): array
    {
        $locales = [];

        foreach ($this->siteLanguages($index) as $language) {
            $locale = strtolower(explode('-', $language)[0]);

            if (in_array($locale, self::LOCALES, true)) {
                $locales[$locale] = $locale;
            }
        }

        return array_values($locales);
    }

    /**
     * The languages of the sites this index covers.
     *
     * @return string[]
     */
    protected function siteLanguages(SearchIndex $index): array
    {
        $languages = [];

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            if ($index->coversSite((int)$site->id)) {
                $languages[] = $site->language;
            }
        }

        return $languages;
    }

    /**
     * The index's searchable fields as Meilisearch attribute names, heaviest first.
     *
     * @return string[]
     */
    private function weightedAttributes(SearchIndex $index): array
    {
        $weights = $index->getFieldWeights();
        arsort($weights);

        return array_map(fn(string $handle) => $this->attribute($handle), array_keys($weights));
    }

    /**
     * Makes sure the Meilisearch index exists before anything is written to it, asking once per
     * index per process rather than before every document.
     */
    private function ensureIndex(SearchIndex $index): void
    {
        if (!$this->indexIsPresent($index)) {
            $this->createIndex($index);
        }
    }

    /**
     * Whether the Meilisearch index exists, asked once per index per process.
     */
    private function indexIsPresent(SearchIndex $index): bool
    {
        $uid = $this->indexUid($index);

        if (isset($this->_ensured[$uid])) {
            return true;
        }

        if (!$this->getClient()->indexExists($uid)) {
            return false;
        }

        return $this->_ensured[$uid] = true;
    }

    private function documentId(int $elementId, int $siteId): string
    {
        return $elementId . '_' . $siteId;
    }

    private function attribute(string $handle): string
    {
        return self::CONTENT . '.' . $handle;
    }

    /**
     * The parsed terms written back out in Meilisearch's query syntax. Terms are normalized by the
     * time they reach here, and a phrase is quoted so its words have to stay together.
     */
    private function queryString(ParsedQuery $parsed): string
    {
        $written = [];

        foreach ($parsed->getTerms() as $term) {
            $written[] = ($term->excluded ? '-' : '') . $this->writeTerm($term);
        }

        return implode(' ', $written);
    }

    private function writeTerm(QueryTerm $term): string
    {
        if ($term->phrase || str_contains($term->text, ' ')) {
            return '"' . str_replace('"', '', $term->text) . '"';
        }

        return $term->text;
    }

    /**
     * Meilisearch counts a result set exactly only when it is paged, so a window that lines up with
     * a page is asked for as one. A window at a shifted offset — which is how a page below placed
     * results is read — is asked for from the top and cut here, so the count stays exact either way.
     *
     * @return array{int,int,int} The page, its size, and how many leading hits to drop.
     */
    private function window(SearchQuery $query): array
    {
        if ($query->offset % $query->limit === 0) {
            return [intdiv($query->offset, $query->limit) + 1, $query->limit, 0];
        }

        return [1, $query->offset + $query->limit, $query->offset];
    }

    /**
     * @return array<string,mixed>
     */
    private function highlightingCriteria(SearchQuery $query): array
    {
        return [
            'attributesToHighlight' => ['*'],
            'attributesToCrop' => ['*'],
            'cropLength' => max(self::MIN_CROP_WORDS, intdiv($query->snippetLength, self::CHARACTERS_PER_WORD)),
            'highlightPreTag' => self::HIGHLIGHT_OPEN,
            'highlightPostTag' => self::HIGHLIGHT_CLOSE,
            'showMatchesPosition' => true,
        ];
    }

    /**
     * Everything the search has to be narrowed by, as Meilisearch filter expressions joined by AND.
     *
     * @param string[] $elementTypes
     * @return string[]
     */
    private function filterExpressions(SearchQuery $query, SearchIndex $index, array $elementTypes): array
    {
        $expressions = [];

        // Nothing narrowing the search on either side means every site the index holds.
        $scope = $query->getSiteScope($index->siteId);

        if ($scope !== null) {
            $expressions[] = count($scope) === 1
                ? self::ATTRIBUTE_SITE_ID . ' = ' . $scope[0]
                : self::ATTRIBUTE_SITE_ID . ' IN [' . implode(', ', $scope) . ']';
        }

        // Only what this index still searches, so a document left from an older configuration
        // cannot come back as a result.
        $expressions[] = $this->anyOf(self::ATTRIBUTE_ELEMENT_TYPE, $elementTypes);

        foreach ($query->getFilters() as $filter) {
            $expressions[] = $this->filterExpression($filter, $index, $elementTypes);
        }

        foreach ($query->getExcludedElements() as $excluded) {
            $expressions[] = $this->exclusionExpression($excluded['elementId'], $excluded['siteId']);
        }

        return $expressions;
    }

    /**
     * @param string[] $elementTypes
     */
    private function filterExpression(SearchFilter $filter, SearchIndex $index, array $elementTypes): string
    {
        $attribute = $this->filterAttribute($filter->field, $index);

        $values = array_map(
            fn(mixed $value) => $this->literal($value, $filter->field, $elementTypes),
            is_array($filter->value) ? $filter->value : [$filter->value],
        );

        if ($filter->operator->isComparison() && !in_array($attribute, self::NUMERIC_ATTRIBUTES, true)) {
            // Searchable content is stored as the text a Craft field reduced to, which Meilisearch
            // cannot order or compare. Refused rather than answered with something else.
            throw new InvalidQueryException(
                "“{$filter->field}” cannot be compared.",
                ['filters' => ["“{$filter->field}” is indexed as text, which cannot be compared."]],
            );
        }

        return match ($filter->operator) {
            FilterOperator::Equals => "$attribute = {$values[0]}",
            FilterOperator::NotEquals => "$attribute != {$values[0]}",
            FilterOperator::In => $attribute . ' IN [' . implode(', ', $values) . ']',
            FilterOperator::NotIn => $attribute . ' NOT IN [' . implode(', ', $values) . ']',
            FilterOperator::GreaterThan => "$attribute > {$values[0]}",
            FilterOperator::GreaterThanOrEquals => "$attribute >= {$values[0]}",
            FilterOperator::LessThan => "$attribute < {$values[0]}",
            FilterOperator::LessThanOrEquals => "$attribute <= {$values[0]}",
            FilterOperator::Between => "($attribute >= {$values[0]} AND $attribute <= {$values[1]})",
        };
    }

    /**
     * The Meilisearch attribute a filter names: an identity attribute, or a field this index
     * actually searches. Anything else is a mistake rather than an empty result.
     */
    private function filterAttribute(string $field, SearchIndex $index): string
    {
        if ($field === self::FILTER_ELEMENT_ID || $field === self::ATTRIBUTE_ELEMENT_ID) {
            return self::ATTRIBUTE_ELEMENT_ID;
        }

        if ($field === self::ATTRIBUTE_SITE_ID || $field === self::ATTRIBUTE_ELEMENT_TYPE) {
            return $field;
        }

        if (array_key_exists($field, $index->getFieldWeights())) {
            return $this->attribute($field);
        }

        throw new InvalidQueryException(
            "“{$field}” cannot be filtered on in the “{$index->handle}” search index.",
            ['filters' => ["“{$field}” is not a field this index searches."]],
        );
    }

    /**
     * A filter value as a Meilisearch literal. Strings go through JSON encoding, so nothing a
     * caller passes can close a quote and become part of the expression.
     *
     * @param string[] $elementTypes
     */
    private function literal(mixed $value, string $field, array $elementTypes): string
    {
        if ($field === self::ATTRIBUTE_ELEMENT_TYPE) {
            $value = $this->resolveElementType((string)$value, $elementTypes);
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return Json::encode((string)$value);
    }

    /**
     * Accepts a class name or Craft's own reference handle, so templates need not name classes.
     *
     * @param string[] $elementTypes
     */
    private function resolveElementType(string $value, array $elementTypes): string
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
     * @param string[] $values
     */
    private function anyOf(string $attribute, array $values): string
    {
        return $attribute . ' IN [' . implode(', ', array_map(static fn(string $value) => Json::encode($value), $values)) . ']';
    }

    /**
     * Leaves one result out of the search entirely, so it cannot appear on any page and is not
     * counted either. A removal naming a site takes only that site's copy.
     */
    private function exclusionExpression(int $elementId, ?int $siteId): string
    {
        if ($siteId === null) {
            return self::ATTRIBUTE_ELEMENT_ID . ' != ' . $elementId;
        }

        return 'NOT (' . self::ATTRIBUTE_ELEMENT_ID . " = $elementId AND " . self::ATTRIBUTE_SITE_ID . " = $siteId)";
    }

    /**
     * @return string[]
     */
    private function sortExpressions(SearchQuery $query, SearchIndex $index): array
    {
        $sorts = [];

        foreach ($query->getSorts() as $sort) {
            // Relevance is what Meilisearch orders by when nothing else is asked for, and it has no
            // name in the sort list, so it simply contributes nothing here.
            if ($sort->isScore()) {
                continue;
            }

            $sorts[] = $this->sortAttribute($sort->field, $index) . ':'
                . ($sort->direction === SortDirection::Asc ? 'asc' : 'desc');
        }

        return $sorts;
    }

    private function sortAttribute(string $field, SearchIndex $index): string
    {
        if (in_array($field, self::NUMERIC_ATTRIBUTES, true)) {
            return $field;
        }

        if ($field === self::FILTER_ELEMENT_ID) {
            return self::ATTRIBUTE_ELEMENT_ID;
        }

        if (array_key_exists($field, $index->getFieldWeights())) {
            return $this->attribute($field);
        }

        throw new InvalidQueryException(
            "Results cannot be sorted by “{$field}”.",
            ['sorts' => ["“{$field}” is not a field the “{$index->handle}” search index can sort by."]],
        );
    }

    /**
     * @param array<string,mixed> $response
     * @return SearchHit[]
     */
    private function readHits(array $response, SearchQuery $query): array
    {
        $hits = [];

        foreach ((array)$response['hits'] as $document) {
            if (!is_array($document) || !isset($document[self::ATTRIBUTE_ELEMENT_ID])) {
                throw new ProviderException('Meilisearch returned a result SearchKit cannot identify.');
            }

            $hit = new SearchHit([
                'elementId' => (int)$document[self::ATTRIBUTE_ELEMENT_ID],
                'siteId' => isset($document[self::ATTRIBUTE_SITE_ID]) ? (int)$document[self::ATTRIBUTE_SITE_ID] : null,
                'elementType' => isset($document[self::ATTRIBUTE_ELEMENT_TYPE])
                    ? (string)$document[self::ATTRIBUTE_ELEMENT_TYPE]
                    : null,
                'score' => (float)($document['_rankingScore'] ?? 0),
            ]);

            if ($query->highlight) {
                $this->readHighlights($hit, $document);
            }

            $hits[] = $hit;
        }

        return $hits;
    }

    /**
     * What Meilisearch matched and the excerpts it cropped, as plain text and with the matches
     * marked. Everything is escaped before the marks go in, so indexed content cannot carry markup
     * out of a snippet.
     *
     * @param array<string,mixed> $document
     */
    private function readHighlights(SearchHit $hit, array $document): void
    {
        foreach ((array)(($document['_formatted'] ?? [])[self::CONTENT] ?? []) as $handle => $value) {
            if (!is_string($value) || !str_contains($value, self::HIGHLIGHT_OPEN)) {
                continue;
            }

            $excerpt = $this->plainText($value);

            $hit->snippets[(string)$handle] = str_replace([self::HIGHLIGHT_OPEN, self::HIGHLIGHT_CLOSE], '', $excerpt);
            $hit->highlights[(string)$handle] = str_replace(
                [Html::encode(self::HIGHLIGHT_OPEN), Html::encode(self::HIGHLIGHT_CLOSE)],
                ['<mark>', '</mark>'],
                Html::encode($excerpt),
            );
        }

        foreach (array_keys((array)($document['_matchesPosition'] ?? [])) as $attribute) {
            if (str_starts_with((string)$attribute, self::CONTENT . '.')) {
                $hit->matchedFields[] = substr((string)$attribute, strlen(self::CONTENT) + 1);
            }
        }

        if ($hit->matchedFields === []) {
            $hit->matchedFields = array_keys($hit->highlights);
        }
    }

    /**
     * Values are keywords a Craft field produced, which may still carry markup a snippet should not.
     * The marks Meilisearch left are control characters, so neither step here can disturb them.
     */
    private function plainText(string $value): string
    {
        $stripped = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string)preg_replace('/\s+/u', ' ', $stripped));
    }

    /**
     * A setting with any environment variable in it resolved, or null when it names one that is
     * not set — which is a configuration mistake rather than a value to send.
     */
    private function resolved(string $value, ?string $default = null): ?string
    {
        if ($value === '') {
            return $default;
        }

        // An environment variable that is not set comes back as null, which is a configuration
        // mistake rather than a value worth sending anywhere.
        $parsed = App::parseEnv($value);

        return is_string($parsed) ? $parsed : $default;
    }
}
