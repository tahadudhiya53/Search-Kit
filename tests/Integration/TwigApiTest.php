<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Entry;
use craft\web\View;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Throwable;

/**
 * Runs real templates through Craft's own template engine, which is the only way to prove
 * `craft.searchKit` is reachable and prints what a front end needs.
 */
class TwigApiTest extends ContentTestCase
{
    private const TERM = 'zqxwtwigland';

    private SearchIndex $index;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->sectionSiteId(),
        );

        foreach (['Zqxwtwigland Summer Hat', 'Zqxwtwigland Winter Boots'] as $title) {
            $entry = $this->createEntry($title);
            Craft::$app->getSearch()->indexElementAttributes($entry, ['title']);
        }
    }

    public function testATemplateCanRunASearch(): void
    {
        self::assertSame('2', $this->render('{{ craft.searchKit.search(index, term).total }}'));
    }

    public function testATemplateCanReadTheElementsAndPagination(): void
    {
        $output = $this->render(<<<'TWIG'
            {%- set results = craft.searchKit.search(index, term, { limit: 1, page: 1, orderBy: 'title asc' }) -%}
            {{ results.page }}/{{ results.pageCount }} of {{ results.total }}
            {%- for hit in results.hits %} | {{ hit.element.title }}{% endfor -%}
            {{ results.hasNextPage ? ' | more' : '' }}
            TWIG);

        self::assertSame('1/2 of 2 | Zqxwtwigland Summer Hat | more', $output);
    }

    public function testATemplateCanFilterAndPrintHighlights(): void
    {
        $output = $this->render(<<<'TWIG'
            {%- set results = craft.searchKit.search(index, term, {
                filters: { section: section },
                highlight: true,
                orderBy: 'title asc',
            }) -%}
            {%- for hit in results.hits %}{{ hit.highlight }};{% endfor -%}
            TWIG);

        // Printed without `|raw`: the marks survive, and the text around them is escaped.
        self::assertSame(
            '<mark>Zqxwtwigland</mark> Summer Hat;<mark>Zqxwtwigland</mark> Winter Boots;',
            $output,
        );
    }

    public function testATemplateCanReadSnippetsAsPlainText(): void
    {
        $output = $this->render(
            "{%- set results = craft.searchKit.search(index, term, { highlight: true, orderBy: 'title asc' }) -%}"
            . '{{ results.hits[0].snippet }}',
        );

        self::assertSame('Zqxwtwigland Summer Hat', $output);
    }

    public function testATemplateCanBuildAQueryAndRunItAfterwards(): void
    {
        $output = $this->render(<<<'TWIG'
            {%- set query = craft.searchKit.query(index, term, { limit: 5, page: 2 }) -%}
            {{ query.offset }}:{{ craft.searchKit.search(query).total }}
            TWIG);

        self::assertSame('5:2', $output);
    }

    public function testAnInvalidSearchFromATemplateIsReported(): void
    {
        try {
            $this->render("{{ craft.searchKit.search(index, '   ').total }}");
            self::fail('A blank search should be rejected in a template too.');
        } catch (Throwable $e) {
            self::assertTrue(
                $this->isCausedByAnInvalidQuery($e),
                'A template should see the same invalid-query failure as PHP does.',
            );
        }
    }

    private function isCausedByAnInvalidQuery(?Throwable $e): bool
    {
        while ($e !== null) {
            if ($e instanceof InvalidQueryException) {
                return true;
            }

            $e = $e->getPrevious();
        }

        return false;
    }

    private function render(string $template): string
    {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            return $view->renderString($template, [
                'index' => $this->index->handle,
                'term' => self::TERM,
                'section' => $this->section()->handle,
            ]);
        } finally {
            $view->setTemplateMode($mode);
        }
    }
}
