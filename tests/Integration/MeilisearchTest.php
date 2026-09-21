<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\enums\RuleMatchType;
use Tahadudhiya\SearchKit\enums\SortDirection;
use Tahadudhiya\SearchKit\models\RuleAction;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\models\SearchRule;
use Tahadudhiya\SearchKit\providers\MeilisearchProvider;
use Throwable;

/**
 * Runs SearchKit against a real Meilisearch server, so nothing here is taken on trust from a
 * double. Skipped when no server is configured, since a passing run must mean the engine answered.
 */
class MeilisearchTest extends SearchContentTestCase
{
    /** @var string Names the Meilisearch server these tests run against. */
    public const URL_VARIABLE = 'SEARCHKIT_MEILISEARCH_URL';

    /** @var string A word no other content in the project can match. */
    private const TERM = 'zqxmeilisearch';

    /** @var float How long Meilisearch's own queue is given to settle before a test gives up. */
    private const SETTLE_TIMEOUT = 20.0;

    private string $url;
    private SearchIndex $index;

    /** @var SearchRule[] */
    private array $createdRules = [];

    protected function setUp(): void
    {
        parent::setUp();

        $url = (string)(getenv(self::URL_VARIABLE) ?: '');

        if ($url === '') {
            self::markTestSkipped('Set ' . self::URL_VARIABLE . ' to a Meilisearch server to run these tests.');
        }

        $this->url = rtrim($url, '/');
        $this->index = $this->meilisearchIndex();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRules as $rule) {
            $this->plugin()->getRules()->deleteRule($rule);
        }

        $this->createdRules = [];

        // Only ever the index this test created, named after its own random handle.
        try {
            $this->provider()->getClient()->deleteIndex($this->index->handle);
        } catch (Throwable) {
            // A test that never reached the server has nothing to clean up.
        }

        parent::tearDown();
    }

    public function testContentReachesMeilisearchAndComesBackAsAResult(): void
    {
        $boots = $this->page('Alpine boots');
        $this->page('Summer sandals');
        $this->drain();

        $result = $this->search('boots');

        self::assertSame([(int)$boots->id], $result->getElementIds());
        self::assertSame(1, $result->total);
        self::assertNotNull($result->hits[0]->element);
    }

    public function testAnEditedElementIsReindexedRatherThanDuplicated(): void
    {
        $entry = $this->page('Alpine boots');
        $this->drain();

        $entry->title = 'Alpine sandals ' . self::TERM;
        self::assertTrue(Craft::$app->getElements()->saveElement($entry));
        $this->drain();

        self::assertSame([], $this->search('boots')->getElementIds());
        self::assertSame([(int)$entry->id], $this->search('sandals')->getElementIds());
    }

    public function testADeletedElementStopsBeingAResult(): void
    {
        $entry = $this->page('Alpine boots');
        $this->drain();

        self::assertSame([(int)$entry->id], $this->search('boots')->getElementIds());

        Craft::$app->getElements()->deleteElement($entry, true);
        $this->drain();

        self::assertSame([], $this->search('boots')->getElementIds());
    }

    public function testARebuildFillsAnEmptyIndexFromTheContentItself(): void
    {
        // Nothing has been handed to Meilisearch yet, so whatever the search finds afterwards
        // was put there by the walk rather than by the save that tracked it.
        $boots = $this->page('Alpine boots');

        $rebuild = $this->plugin()->getIndexing()->rebuild($this->index);
        self::assertSame(0, $rebuild->failed);

        $this->settle();

        self::assertContains((int)$boots->id, $this->search('boots')->getElementIds());
    }

    public function testAWriteMeilisearchAcceptsAndThenGivesUpOnIsNotReportedAsIndexed(): void
    {
        // Built by hand with a primary key SearchKit's documents do not carry, so Meilisearch
        // accepts every write and only afterwards refuses it — a failure the write's own response
        // cannot show.
        $client = $this->provider()->getClient();
        $client->waitForTask($client->createIndex($this->index->handle, 'aKeyNoDocumentCarries'));

        $this->page('Alpine boots');

        $result = $this->plugin()->getIndexing()->processPending($this->index);

        self::assertSame(0, $result->processed);
        self::assertSame(1, $result->failed);
        self::assertFalse($result->isComplete());

        // Kept for another attempt rather than settled on a write that never landed, and the index
        // is not reported as having been indexed.
        self::assertSame(1, $this->pendingCount($this->index));
        self::assertNull($this->freshIndexes()->getIndexByHandle($this->index->handle)?->dateLastIndexed);
    }

    public function testARebuildDoesNotInheritWhatWasQueuedAgainstTheIndexItDiscarded(): void
    {
        $boots = $this->page('Alpine boots');
        $this->drain();

        $client = $this->provider()->getClient();

        // One document from outside the walk, waited on so it is certainly in the index the
        // rebuild is about to discard — which is what makes the assertion below mean something.
        $client->waitForTask($client->addDocuments($this->index->handle, [$this->foreignDocument(999999)]));
        self::assertSame(1, $this->countMatching('elementId = 999999'));

        // And one deliberately not waited on, standing in for a write still in Meilisearch's own
        // queue as the rebuild begins.
        $client->addDocuments($this->index->handle, [$this->foreignDocument(999998)]);

        $rebuild = $this->plugin()->getIndexing()->rebuild($this->index);
        self::assertSame(0, $rebuild->failed);

        // Asked of Meilisearch directly, and by identity rather than by text: a hit SearchKit
        // cannot load an element for is dropped on the way out, so a search through SearchKit
        // could not tell whether it survived.
        self::assertSame(0, $this->countMatching('elementId IN [999999, 999998]'));
        self::assertContains((int)$boots->id, $this->search('boots')->getElementIds());
    }

    public function testResultsCanBeFilteredAndSortedByWhatWasIndexed(): void
    {
        $wet = $this->page('Alpine boots', [self::FIELD => 'wetweather']);
        $this->page('Summer boots', [self::FIELD => 'dryweather']);
        $this->drain();

        $filtered = $this->search('boots', ['filters' => [self::FIELD => 'wetweather']]);
        self::assertSame([(int)$wet->id], $filtered->getElementIds());

        $ascending = $this->sorted(SortDirection::Asc);

        self::assertCount(2, $ascending);
        self::assertSame(array_reverse($ascending), $this->sorted(SortDirection::Desc));
    }

    public function testMeilisearchCountsTheWholeResultSetByAField(): void
    {
        $this->page('Alpine boots', [self::FIELD => 'wetweather']);
        $this->page('Summer boots', [self::FIELD => 'dryweather']);
        $this->page('Winter boots', [self::FIELD => 'wetweather']);
        $this->drain();

        // One result on the page, but the counts describe all three.
        $result = $this->search('boots', ['facets' => self::FIELD, 'limit' => 1]);

        self::assertCount(1, $result->hits);
        self::assertSame(
            ['wetweather' => 2, 'dryweather' => 1],
            $result->getFacet(self::FIELD)?->getCounts(),
        );
    }

    public function testMeilisearchAnalysesContentInTheLanguagesItsSitesAreWrittenIn(): void
    {
        $this->provider()->rebuild($this->index);
        $this->settle();

        $client = Craft::createGuzzleClient(['base_uri' => $this->url . '/', 'timeout' => 5]);
        $response = $client->request('GET', 'indexes/' . $this->index->handle . '/settings');
        $settings = json_decode((string)$response->getBody(), true);
        $localized = $settings['localizedAttributes'][0] ?? [];

        // Meilisearch answers in three-letter codes whatever it was given, so the languages are
        // compared as it holds them rather than as Craft writes them.
        self::assertNotEmpty($localized['locales'] ?? [], 'Meilisearch was told nothing about the language.');
        self::assertSame(['fields.*'], $localized['attributePatterns'] ?? []);
    }

    public function testMeilisearchMarksWhatItMatchedRatherThanSearchKitWorkingItOut(): void
    {
        $this->page('Alpine boots for deep snow');
        $this->drain();

        $hit = $this->search('boots', ['highlight' => true])->hits[0];

        self::assertContains('title', $hit->matchedFields);
        self::assertStringContainsString('<mark>boots</mark>', (string)$hit->getHighlight());
        self::assertStringNotContainsString('<mark>', (string)$hit->getSnippet());
    }

    public function testMeilisearchCorrectsATypoWithoutSearchKitRetryingTheSearch(): void
    {
        $this->page('Alpine boots');
        $this->drain();

        $result = $this->search('bootz');

        self::assertNotSame([], $result->getElementIds());
        // The engine tolerated the typo itself, so SearchKit never searched for anything else.
        self::assertFalse($result->wasCorrected());
    }

    public function testSearchRulesGovernMeilisearchResultsAsTheyDoAnyOther(): void
    {
        $alpha = $this->page('Alpha');
        $beta = $this->page('Beta');
        $this->page('Gamma');
        $this->drain();

        $this->rule([
            new RuleAction([
                'type' => RuleActionType::Hide,
                'elementId' => (int)$alpha->id,
                'elementType' => Entry::class,
            ]),
            new RuleAction([
                'type' => RuleActionType::Pin,
                'elementId' => (int)$beta->id,
                'elementType' => Entry::class,
                'position' => 1,
            ]),
        ]);

        $result = $this->search(self::TERM);
        $ids = $result->getElementIds();

        // Hiding is exact at any depth, so the total describes the final set rather than the page.
        self::assertNotContains((int)$alpha->id, $ids);
        self::assertSame((int)$beta->id, $ids[0]);
        self::assertSame(2, $result->total);
        self::assertTrue($result->hits[0]->pinned);
    }

    public function testTheDebuggerExplainsAMeilisearchSearchWithoutShowingHowToReachIt(): void
    {
        $this->page('Alpine boots');
        $this->drain();

        $query = SearchQuery::create($this->index->handle, 'boots', ['limit' => 20]);
        $debug = $query->startDebug();
        $this->plugin()->getSearch()->search($query);

        self::assertSame(MeilisearchProvider::class, $debug->provider);

        $reported = array_merge(...array_map(
            static fn(array $execution) => (array)($execution['request'] ?? []),
            $debug->executions,
        ));

        self::assertArrayHasKey('meilisearchQuery', $reported);
        self::assertArrayNotHasKey('url', $reported);
        self::assertArrayNotHasKey('apiKey', $reported);
    }

    public function testAServerThatCannotBeReachedIsReportedRatherThanThrown(): void
    {
        $index = $this->newIndex(MeilisearchProvider::class);
        $index->settings = ['url' => 'http://127.0.0.1:1'];

        $status = $this->plugin()->getIndexing()->getStatus($index);

        self::assertFalse($status->provider->available);
        self::assertNotNull($status->provider->message);
        self::assertStringNotContainsString('127.0.0.1', (string)$status->provider->message);
    }

    /**
     * A document no walk of this project's content would ever produce.
     *
     * @return array<string,mixed>
     */
    private function foreignDocument(int $elementId): array
    {
        return [
            'id' => $elementId . '_1',
            'elementId' => $elementId,
            'siteId' => $this->fieldSectionSiteId(),
            'elementType' => Entry::class,
            'fields' => ['title' => 'Foreign ' . self::TERM],
        ];
    }

    /**
     * How many documents the Meilisearch index itself holds for a filter, asked without SearchKit
     * in the way.
     */
    private function countMatching(string $filter): int
    {
        $response = $this->provider()->getClient()->search($this->index->handle, [
            'q' => '',
            'filter' => [$filter],
            'page' => 1,
            'hitsPerPage' => 10,
        ]);

        return (int)$response['totalHits'];
    }

    /**
     * An index served by Meilisearch, over the fields these tests search and filter on.
     */
    private function meilisearchIndex(): SearchIndex
    {
        $index = $this->newIndex(MeilisearchProvider::class);
        $index->siteId = $this->fieldSectionSiteId();
        $index->settings = ['url' => $this->url];

        $this->persistIndex($index);

        self::assertTrue($this->plugin()->getSearchableFields()->saveFieldsForIndex($index, [
            new SearchableField(['elementType' => Entry::class, 'handle' => 'title', 'weight' => 10]),
            new SearchableField(['elementType' => Entry::class, 'handle' => self::FIELD, 'weight' => 2]),
        ]));

        return $index;
    }

    private function provider(): MeilisearchProvider
    {
        /** @var MeilisearchProvider $provider */
        $provider = $this->plugin()->getProviders()->getProviderForIndex($this->index);

        return $provider;
    }

    /**
     * @param array<string,mixed> $values
     */
    private function page(string $title, array $values = []): Entry
    {
        return $this->createPage($title . ' ' . self::TERM, $values);
    }

    /**
     * Hands Meilisearch everything the index owes, then waits for it to have applied it.
     */
    private function drain(): void
    {
        $result = $this->plugin()->getIndexing()->processPending($this->index);

        self::assertSame(0, $result->failed, 'Meilisearch refused some of the content.');

        $this->settle();
    }

    /**
     * Meilisearch queues what it is given, so a test that searched straight away would be asking
     * before the engine had finished writing.
     */
    private function settle(): void
    {
        $deadline = microtime(true) + self::SETTLE_TIMEOUT;
        $client = Craft::createGuzzleClient(['base_uri' => $this->url . '/', 'timeout' => 5]);

        do {
            $response = $client->request('GET', 'tasks', [
                'query' => ['indexUids' => $this->index->handle, 'statuses' => 'enqueued,processing'],
                'http_errors' => false,
            ]);

            $body = json_decode((string)$response->getBody(), true);

            if (is_array($body) && ($body['results'] ?? null) === []) {
                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail('Meilisearch did not finish applying what it was given.');
    }

    /**
     * @param array<string,mixed> $params
     */
    private function search(string $text, array $params = []): SearchResult
    {
        return $this->plugin()->getSearch()->search(
            SearchQuery::create($this->index->handle, $text, $params + ['limit' => 20]),
        );
    }

    /**
     * @return int[]
     */
    private function sorted(SortDirection $direction): array
    {
        return $this->search('boots', ['orderBy' => self::FIELD . ' ' . $direction->value])->getElementIds();
    }

    /**
     * @param RuleAction[] $actions
     */
    private function rule(array $actions): SearchRule
    {
        $rule = new SearchRule([
            'indexId' => $this->index->id,
            'name' => 'Meilisearch test rule',
            'matchType' => RuleMatchType::Exact,
            'matchValue' => self::TERM,
        ]);

        $rule->setActions($actions);

        self::assertTrue(
            $this->plugin()->getRules()->saveRule($rule),
            implode(' ', $rule->getErrorSummary(true)),
        );

        $this->createdRules[] = $rule;

        return $rule;
    }
}
