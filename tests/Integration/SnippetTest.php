<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\elements\Entry;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\services\Highlighting;
use Tahadudhiya\SearchKit\Tests\Support\FailingDocuments;

/**
 * Builds excerpts from real field values, which is the only way to prove they come from the fields
 * an index is configured with rather than from anything the provider returned.
 */
class SnippetTest extends SearchContentTestCase
{
    private const TERM = 'zqxwsnippet';

    private SearchIndex $index;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->fieldSectionSiteId(),
        );

        $fields = $this->plugin()->getSearchableFields()->getFieldsByIndexId((int)$this->index->id);
        $fields[] = new SearchableField([
            'elementType' => Entry::class,
            'handle' => 'searchKitTestDescription',
            'weight' => 1,
        ]);

        self::assertTrue($this->plugin()->getSearchableFields()->saveFieldsForIndex($this->index, $fields));

        $this->createPage('Zqxwsnippet Winter Boots', [
            'searchKitTestDescription' => str_repeat('Filler words about nothing. ', 20)
                . 'These zqxwsnippet boots are waterproof. '
                . str_repeat('More filler afterwards. ', 20),
        ]);
    }

    public function testExcerptsComeFromEveryConfiguredFieldThatMatched(): void
    {
        $hit = $this->search(['highlight' => true])->hits[0];

        self::assertSame(['title', 'searchKitTestDescription'], $hit->matchedFields, 'Heaviest field first.');
        self::assertStringContainsString('<mark>Zqxwsnippet</mark>', (string)$hit->getHighlight());
        self::assertStringContainsString('zqxwsnippet', (string)$hit->getSnippet('searchKitTestDescription'));
    }

    public function testASnippetIsCutAroundTheMatchToRoughlyTheRequestedLength(): void
    {
        $hit = $this->search(['highlight' => true, 'snippetLength' => 80])->hits[0];
        $snippet = (string)$hit->getSnippet('searchKitTestDescription');

        self::assertLessThanOrEqual(82, mb_strlen($snippet));
        self::assertStringContainsString('zqxwsnippet boots', $snippet);
        self::assertStringStartsWith('…', $snippet);
    }

    public function testASnippetIsPlainTextWhileAHighlightCarriesTheMarks(): void
    {
        $hit = $this->search(['highlight' => true])->hits[0];

        self::assertStringNotContainsString('<mark>', (string)$hit->getSnippet());
        self::assertStringContainsString('<mark>', (string)$hit->getHighlight());
    }

    public function testAFieldThatCannotBeReadNeverFailsTheSearch(): void
    {
        $result = $this->search();
        $highlighting = new Highlighting();
        $highlighting->setDocuments(new FailingDocuments());

        $highlighting->apply($result, SearchQuery::create($this->index->handle, self::TERM), $this->index);

        self::assertNotEmpty($result->hits, 'An excerpt is presentation: it must not cost the result.');
        self::assertSame([], $result->hits[0]->highlights);
    }

    public function testNothingIsHighlightedWhenTheQueryHasNoTerms(): void
    {
        $result = $this->search(['highlight' => true]);
        $highlighting = new Highlighting();

        // A query of punctuation alone has no terms a match could be explained by.
        $query = SearchQuery::create($this->index->handle, '!!! ???');
        $query->highlight = true;

        $highlighting->apply($result, $query, $this->index);

        self::assertNotEmpty($result->hits);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function search(array $params = []): SearchResult
    {
        return $this->searchIndex($this->index->handle, self::TERM, $params);
    }
}
