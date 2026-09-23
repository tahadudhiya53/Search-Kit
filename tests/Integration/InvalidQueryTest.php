<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\elements\Entry;
use Tahadudhiya\SearchKit\errors\IndexDisabledException;
use Tahadudhiya\SearchKit\errors\IndexNotFoundException;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Throwable;

/**
 * Everything the public API must refuse, and what it is allowed to say while refusing it.
 */
class InvalidQueryTest extends SearchContentTestCase
{
    private const TERM = 'zqxwrefuse';
    private const MISSING_SITE_ID = 2147483600;

    private SearchIndex $index;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->fieldSectionSiteId(),
        );
    }

    /**
     * @return iterable<string,array{array<string,mixed>,string}>
     */
    public static function rejectedParameters(): iterable
    {
        yield 'unknown parameter' => [['sections' => ['news']], 'params'];
        yield 'unknown filter operator' => [['filters' => ['slug' => ['roughly' => 1]]], 'filters'];
        yield 'malformed filter' => [['filters' => [['operator' => 'eq']]], 'filters'];
        yield 'filters of the wrong shape' => [['filters' => 'slug'], 'filters'];
        yield 'unknown sort direction' => [['orderBy' => 'title sideways'], 'sorts'];
        yield 'sorting of the wrong shape' => [['orderBy' => 5], 'sorts'];
        yield 'unknown site handle' => [['site' => 'noSuchSiteHandle'], 'siteId'];
    }

    /**
     * @param array<string,mixed> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedParameters')]
    public function testParametersAreRefusedBeforeAnythingRuns(array $params, string $attribute): void
    {
        try {
            SearchQuery::create($this->index->handle, self::TERM, $params);
            self::fail('The parameter should have been refused.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey($attribute, $e->getErrors());
        }
    }

    /**
     * @return iterable<string,array{array<string,mixed>,string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'limit beyond the maximum' => [['limit' => SearchQuery::MAX_LIMIT + 1], 'limit'];
        yield 'limit of nothing' => [['limit' => 0], 'limit'];
        yield 'negative offset' => [['offset' => -5], 'offset'];
        yield 'snippet beyond the maximum' => [['snippetLength' => SearchQuery::MAX_SNIPPET_LENGTH + 1], 'snippetLength'];
        yield 'a filter value nothing can compare' => [['filters' => [['field' => 'slug', 'value' => []]]], 'filters'];
        yield 'a range without both bounds' => [['filters' => ['id' => ['between' => [1]]]], 'filters'];
    }

    /**
     * @param array<string,mixed> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidQueries')]
    public function testInvalidQueriesAreRefusedBeforeTheProviderRuns(array $params, string $attribute): void
    {
        try {
            $this->searchIndex($this->index->handle, self::TERM, $params);
            self::fail('The query should have been refused.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey($attribute, $e->getErrors());
        }
    }

    public function testBlankSearchTextIsRefused(): void
    {
        foreach (['', '   ', "\t\n"] as $text) {
            try {
                $this->searchIndex($this->index->handle, $text);
                self::fail('Blank search text should be refused.');
            } catch (InvalidQueryException $e) {
                self::assertArrayHasKey('text', $e->getErrors());
            }
        }
    }

    public function testAPageBeforeTheFirstIsTreatedAsTheFirst(): void
    {
        $result = $this->searchIndex($this->index->handle, self::TERM, ['page' => 0]);

        self::assertSame(0, $result->offset);
        self::assertSame(1, $result->getPage());
    }

    public function testAnUnknownIndexIsRefused(): void
    {
        $this->expectException(IndexNotFoundException::class);
        $this->searchIndex('noSuchIndexHandle', self::TERM);
    }

    public function testADisabledIndexIsRefused(): void
    {
        $this->index->enabled = false;
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($this->index));

        $this->expectException(IndexDisabledException::class);
        $this->searchIndex($this->index->handle, self::TERM);
    }

    public function testAnUnknownFilterFieldIsRefused(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->searchIndex($this->index->handle, self::TERM, ['filters' => ['nonsense' => 'value']]);
    }

    public function testAnUnknownElementTypeIsRefused(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->searchIndex($this->index->handle, self::TERM, ['filters' => ['elementType' => 'widget']]);
    }

    public function testAnUnsortableFieldIsRefused(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->searchIndex($this->index->handle, self::TERM, ['orderBy' => 'relevancy desc']);
    }

    public function testASiteOutsideTheIndexScopeIsRefused(): void
    {
        $other = $this->aSiteOtherThan($this->fieldSectionSiteId());

        $this->assertRefusedSite($this->index->handle, $other, "outside this index's scope");
    }

    public function testASiteThatDoesNotExistIsRefusedByAScopedIndex(): void
    {
        $this->assertRefusedSite($this->index->handle, self::MISSING_SITE_ID, 'No site exists');
    }

    public function testASiteThatDoesNotExistIsRefusedByAnAllSiteIndex(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title'], CraftProvider::class);

        // Without this an all-site index would happily run and return nothing at all.
        $this->assertRefusedSite($index->handle, self::MISSING_SITE_ID, 'No site exists');
    }

    public function testFilteringTheSameFieldTwiceIsRefused(): void
    {
        $query = SearchQuery::create($this->index->handle, self::TERM, [
            'filters' => [['field' => 'slug', 'value' => 'a'], ['field' => 'slug', 'value' => 'b']],
        ]);

        $this->expectException(InvalidQueryException::class);
        $this->plugin()->getSearch()->search($query);
    }

    public function testAProviderFailureSaysNothingAboutWhatWentWrongInside(): void
    {
        // An element type that cannot be loaded is the simplest real failure inside the provider.
        $index = $this->newIndex();
        $index->setFields([new SearchableField([
            'elementType' => 'Tahadudhiya\SearchKit\NotAnElementType',
            'handle' => 'title',
        ])]);

        try {
            (new CraftProvider())->search(SearchQuery::create($index->handle, self::TERM), $index);
            self::fail('A provider failure should surface as a SearchKit exception.');
        } catch (ProviderException $e) {
            self::assertStringNotContainsString('Class "', $e->getMessage());
            self::assertStringNotContainsString('not found', $e->getMessage());
            self::assertNotNull($e->getPrevious(), 'The underlying failure must stay available for logging.');
        }
    }

    private function assertRefusedSite(string $handle, int $siteId, string $expectedDetail): void
    {
        try {
            $this->searchIndex($handle, self::TERM, ['site' => $siteId]);
            self::fail('An unusable site should be refused.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('siteId', $e->getErrors());
            self::assertStringContainsString($expectedDetail, implode(' ', $e->getErrors()['siteId']));
        }
    }

    public function testRefusalsSayNothingAboutHowSearchKitWorksInside(): void
    {
        $forbidden = ['SELECT', 'SQLSTATE', 'craft_', '/var/www', '.php', 'PDO', 'password'];

        foreach ($this->everyRefusal() as $message) {
            foreach ($forbidden as $fragment) {
                self::assertStringNotContainsStringIgnoringCase($fragment, $message);
            }
        }
    }

    /**
     * @return string[]
     */
    private function everyRefusal(): array
    {
        $messages = [];

        foreach ([
            fn() => $this->searchIndex($this->index->handle, '  '),
            fn() => $this->searchIndex($this->index->handle, self::TERM, ['filters' => ['nonsense' => 'v']]),
            fn() => $this->searchIndex($this->index->handle, self::TERM, ['orderBy' => 'relevancy']),
            fn() => $this->searchIndex('noSuchIndexHandle', self::TERM),
            fn() => SearchQuery::create($this->index->handle, self::TERM, ['site' => 'noSuchSiteHandle']),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('This attempt should have been refused.');
            } catch (Throwable $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertCount(5, $messages);

        return $messages;
    }
}
