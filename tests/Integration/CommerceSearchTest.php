<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\models\RuleAction;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\models\SearchRule;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * Real Commerce products and variants, indexed, filtered, synchronised and merchandised by
 * SearchKit's own services — the only thing that can show the integration actually works.
 */
class CommerceSearchTest extends CommerceTestCase
{
    private const TERM = 'zqxwcommerce';

    private SearchIndex $index;

    private Product $cheap;

    private Product $dear;

    /** @var SearchRule[] */
    private array $createdRules = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->commerceIndex([
            [Product::class, 'title'],
            [Product::class, 'defaultSku'],
            [Product::class, static::FIELD],
            [Variant::class, 'title'],
            [Variant::class, 'sku'],
            [Variant::class, 'price'],
            [Variant::class, static::FIELD],
        ], $this->primarySiteId());

        $this->cheap = $this->createProduct('Zqxwcommerce Cheap', [
            ['sku' => 'ZQXWCHEAP', 'price' => 10, 'default' => true, 'keyword' => 'budgetword'],
            ['sku' => 'ZQXWCHEAPTWO', 'price' => 15, 'tracked' => true, 'stock' => 4, 'keyword' => 'budgetword'],
        ], [static::FIELD => 'budgetword']);

        $this->dear = $this->createProduct('Zqxwcommerce Dear', [
            ['sku' => 'ZQXWDEAR', 'price' => 99, 'default' => true, 'tracked' => true, 'stock' => 0, 'keyword' => 'luxuryword'],
        ], [static::FIELD => 'luxuryword']);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRules as $rule) {
            $this->plugin()->getRules()->deleteRule($rule);
        }

        $this->createdRules = [];

        parent::tearDown();
    }

    public function testProductsAndVariantsAreIndexableWithTheirOwnFields(): void
    {
        $fields = $this->freshSearchableFields();

        self::assertContains(Product::class, $fields->getIndexableElementTypes());
        self::assertContains(Variant::class, $fields->getIndexableElementTypes());

        $product = $fields->getAvailableHandles(Product::class);
        $variant = $fields->getAvailableHandles(Variant::class);

        // Commerce's own searchable attributes, plus a custom field on each Commerce layout.
        self::assertContains('defaultSku', $product);
        self::assertContains('title', $product);
        self::assertContains(static::FIELD, $product);

        self::assertContains('sku', $variant);
        self::assertContains('price', $variant);
        self::assertContains('description', $variant);
        self::assertContains(static::FIELD, $variant);
    }

    public function testDocumentsCarryRealCommerceValues(): void
    {
        $documents = $this->plugin()->getDocuments();

        $product = $documents->buildDocument($this->index, $this->cheap);
        self::assertStringContainsStringIgnoringCase('zqxwcheap', $product->getFields()['defaultSku'] ?? '');
        self::assertStringContainsString('budgetword', $product->getFields()[static::FIELD] ?? '');

        $variant = $documents->buildDocument($this->index, $this->cheap->getVariants()[1]);
        self::assertStringContainsStringIgnoringCase('zqxwcheaptwo', $variant->getFields()['sku'] ?? '');
        self::assertStringContainsString('15', $variant->getFields()['price'] ?? '');
        self::assertStringContainsString('budgetword', $variant->getFields()[static::FIELD] ?? '');
    }

    public function testProductsAndVariantsAreSearchedByTheSameService(): void
    {
        $ids = $this->search()->getElementIds();

        self::assertContains($this->cheap->id, $ids);
        self::assertContains($this->dear->id, $ids);

        // Variants carry the product's title, so one search returns both kinds of result.
        $onlyProducts = $this->search(['filters' => ['elementType' => 'product']]);
        self::assertSame([Product::class], array_values(array_unique(array_map(
            static fn($hit) => $hit->elementType,
            $onlyProducts->hits,
        ))));
    }

    public function testCommerceProductCriteriaNarrowTheResults(): void
    {
        self::assertSame([$this->dear->id], $this->ids(['defaultSku' => 'ZQXWDEAR']));
        self::assertSame([$this->cheap->id], $this->ids(['defaultPrice' => ['lte' => 50]]));
        self::assertSame([$this->dear->id], $this->ids(['defaultPrice' => ['gt' => 50]]));
    }

    public function testAPriceRangeKeepsOnlyTheProductsInsideIt(): void
    {
        self::assertSame([$this->cheap->id], $this->ids(['defaultPrice' => ['between' => [5, 50]]]));
        self::assertSame([$this->dear->id], $this->ids(['defaultPrice' => ['between' => [50, 150]]]));
        self::assertSame([], $this->ids(['defaultPrice' => ['between' => [200, 300]]]));

        // Both bounds are inclusive, so a range written around one price still holds it.
        self::assertSame(['ZQXWDEAR'], $this->skus(['price' => ['between' => [99, 99]]]));
    }

    public function testCommerceVariantCriteriaNarrowTheResults(): void
    {
        self::assertSame(['ZQXWDEAR'], $this->skus(['price' => ['gt' => 50]]));
        self::assertSame(['ZQXWCHEAPTWO'], $this->skus(['sku' => 'ZQXWCHEAPTWO']));
        self::assertSame(['ZQXWCHEAPTWO'], $this->skus(['stock' => ['gt' => 0]]));

        // Untracked stock is unlimited, tracked-and-empty is not: three different questions.
        self::assertSame(['ZQXWCHEAP', 'ZQXWCHEAPTWO'], $this->skus(['hasStock' => true]));
        self::assertSame(['ZQXWCHEAP'], $this->skus(['hasUnlimitedStock' => true]));
        self::assertSame(['ZQXWCHEAPTWO', 'ZQXWDEAR'], $this->skus(['inventoryTracked' => true]));

        self::assertSame(['ZQXWCHEAP', 'ZQXWDEAR'], $this->skus(['isDefault' => true]));
        self::assertSame(['ZQXWCHEAP', 'ZQXWCHEAPTWO'], $this->skus(['productId' => $this->cheap->id]));
        self::assertSame(
            ['ZQXWCHEAP', 'ZQXWCHEAPTWO', 'ZQXWDEAR'],
            $this->skus(['availableForPurchase' => true]),
        );

        // Product type reaches variants through the criteria every element type already offers.
        self::assertSame(
            ['ZQXWCHEAP', 'ZQXWCHEAPTWO', 'ZQXWDEAR'],
            $this->skus(['typeId' => $this->productType()->id]),
        );
    }

    /**
     * Commerce exposes plenty SearchKit deliberately does not offer. Each has to be refused, not
     * quietly ignored, or a template would silently get unfiltered results.
     */
    public function testCommerceCriteriaSearchKitDoesNotOfferAreRefused(): void
    {
        foreach (['promotionalPrice', 'salePrice', 'onPromotion', 'hasSales', 'forCustomer', 'hasVariant', 'shippingCategoryId'] as $criterion) {
            try {
                $this->search(['filters' => [$criterion => 1]]);
                self::fail("“{$criterion}” should have been refused.");
            } catch (InvalidQueryException $e) {
                self::assertArrayHasKey('filters', $e->getErrors());
            }
        }
    }

    public function testTheDefaultVariantReachesItsProduct(): void
    {
        $this->drain($this->index);

        // `defaultSku` is the default variant's, so changing it leaves the product's document stale.
        $variant = $this->defaultVariantOf($this->cheap);
        $variant->sku = 'ZQXWCHEAPCHANGED';
        self::assertTrue(
            Craft::$app->getElements()->saveElement($variant),
            implode(' ', $variant->getErrorSummary(true)),
        );

        self::assertContains($this->cheap->id, $this->pendingElementIds($this->index));
    }

    public function testDeletingTheDefaultVariantReachesItsProduct(): void
    {
        $this->drain($this->index);

        $variant = $this->defaultVariantOf($this->cheap);
        self::assertTrue(Craft::$app->getElements()->deleteElement($variant, true));

        self::assertContains($this->cheap->id, $this->pendingElementIds($this->index));
    }

    /**
     * A product takes nothing from a variant that is not its default, so following one would be
     * work for a document that cannot have changed.
     */
    public function testANonDefaultVariantDoesNotReachItsProduct(): void
    {
        $this->drain($this->index);

        $variant = $this->cheap->getVariants()[1];
        self::assertFalse($variant->isDefault, 'This test needs a variant that is not the default.');

        $variant->sku = 'ZQXWCHEAPTWOCHANGED';
        self::assertTrue(
            Craft::$app->getElements()->saveElement($variant),
            implode(' ', $variant->getErrorSummary(true)),
        );

        $pending = $this->pendingElementIds($this->index);

        self::assertContains($variant->id, $pending, 'The variant itself still has to be reindexed.');
        self::assertNotContains($this->cheap->id, $pending);
    }

    public function testAVariantChangeIsIgnoredWhenNoProductAttributeIsIndexed(): void
    {
        // The dependency is asked of every enabled index at once, so the one that searches
        // `defaultSku` has to be out of the way before the question means anything.
        $this->index->enabled = false;
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($this->index));

        $index = $this->commerceIndex([
            [Product::class, 'title'],
            [Product::class, static::FIELD],
            [Variant::class, 'sku'],
        ], $this->primarySiteId());

        $this->drain($index);

        $variant = $this->defaultVariantOf($this->dear);
        $variant->sku = 'ZQXWDEARCHANGED';
        self::assertTrue(
            Craft::$app->getElements()->saveElement($variant),
            implode(' ', $variant->getErrorSummary(true)),
        );

        $pending = $this->pendingElementIds($index);

        self::assertContains($variant->id, $pending, 'The variant itself still has to be reindexed.');
        self::assertNotContains(
            $this->dear->id,
            $pending,
            'A product indexed only by its own title and custom fields cannot be made stale by a variant.',
        );
    }

    public function testProductsAreMerchandisedByTheExistingRules(): void
    {
        $this->rule([
            new RuleAction([
                'type' => RuleActionType::Hide,
                'elementId' => $this->dear->id,
                'elementType' => Product::class,
            ]),
        ]);

        $ids = $this->search()->getElementIds();

        self::assertNotContains($this->dear->id, $ids);
        self::assertContains($this->cheap->id, $ids);
    }

    public function testProductsArePinnedByTheExistingRules(): void
    {
        $this->rule([
            new RuleAction([
                'type' => RuleActionType::Pin,
                'elementId' => $this->dear->id,
                'elementType' => Product::class,
                'position' => 1,
            ]),
        ]);

        self::assertSame($this->dear->id, $this->search()->getElementIds()[0]);
    }

    /**
     * The relationship is a real Craft relation field on the product layout, pointing at real
     * entries — the project has no categories field, and adding one would change its configuration.
     */
    public function testRelatedToMatchesOnlyTheRelatedProduct(): void
    {
        $x = $this->createRelatedEntry('Zqxwrelated Target X');
        $y = $this->createRelatedEntry('Zqxwrelated Target Y');

        $a = $this->createProduct('Zqxwrelated Product A', [
            ['sku' => 'ZQXWRELA', 'price' => 10, 'default' => true],
        ], [static::RELATION_FIELD => [$x->id]]);

        $b = $this->createProduct('Zqxwrelated Product B', [
            ['sku' => 'ZQXWRELB', 'price' => 20, 'default' => true],
        ], [static::RELATION_FIELD => [$y->id]]);

        $index = $this->commerceIndex([[Product::class, 'title']], $this->primarySiteId());

        $unfiltered = $this->productIds($index, 'zqxwrelated');
        self::assertContains($a->id, $unfiltered);
        self::assertContains($b->id, $unfiltered);

        // Related to X: product A and nothing else.
        self::assertSame([$a->id], $this->productIds($index, 'zqxwrelated', [
            CraftProvider::FILTER_RELATED_TO => $x->id,
        ]));

        self::assertSame([$b->id], $this->productIds($index, 'zqxwrelated', [
            CraftProvider::FILTER_RELATED_TO => $y->id,
        ]));

        // Both, through one `in` filter, which is how several categories would be asked for.
        self::assertEqualsCanonicalizing([$a->id, $b->id], $this->productIds($index, 'zqxwrelated', [
            CraftProvider::FILTER_RELATED_TO => [$x->id, $y->id],
        ]));
    }

    /**
     * One compact multi-site scenario: Commerce content must obey the site rules everything else
     * does, and a product resolved in one site must never be answered from another.
     */
    public function testCommerceContentIsSearchedPerSite(): void
    {
        $sites = Craft::$app->getSites()->getAllSiteIds();

        if (count($sites) < 2) {
            self::markTestSkipped('This project has only one site.');
        }

        [$siteA, $siteB] = [$this->primarySiteId(), (int)$sites[1] === $this->primarySiteId() ? (int)$sites[0] : (int)$sites[1]];

        $product = $this->createProduct('Zqxwsites Default Edition', [
            ['sku' => 'ZQXWSITES', 'price' => 10, 'default' => true],
        ], siteId: $siteA);

        // The same product in the other site, told apart by its own title.
        $inSiteB = Product::find()->id($product->id)->siteId($siteB)->status(null)->one();
        self::assertInstanceOf(Product::class, $inSiteB);
        $inSiteB->title = 'Zqxwsites Uk Edition';
        self::assertTrue(
            Craft::$app->getElements()->saveElement($inSiteB),
            implode(' ', $inSiteB->getErrorSummary(true)),
        );
        $this->indexKeywords($inSiteB);

        $everySite = $this->commerceIndex([[Product::class, 'title'], [Variant::class, 'sku']]);
        $onlySiteA = $this->commerceIndex([[Product::class, 'title']], $siteA);

        // An all-sites index answers for both, each hit naming the site it came from.
        $both = $this->hitsOf($everySite, 'zqxwsites');
        self::assertEqualsCanonicalizing([$siteA, $siteB], array_values(array_unique(array_map(
            static fn($hit) => $hit->siteId,
            array_filter($both, static fn($hit) => $hit->elementType === Product::class),
        ))));

        // Narrowing that index to a site answers for that site alone, with its own content.
        self::assertSame(
            ['Zqxwsites Default Edition'],
            $this->titlesOf($everySite, 'zqxwsites', $siteA),
        );

        self::assertSame(
            ['Zqxwsites Uk Edition'],
            $this->titlesOf($everySite, 'zqxwsites', $siteB),
        );

        // A site-scoped index answers for its own site and cannot be widened to the other.
        foreach ($this->hitsOf($onlySiteA, 'zqxwsites') as $hit) {
            self::assertSame($siteA, $hit->siteId);
        }

        // Ownership survives the split: the variant in each site belongs to the same product.
        $variantHits = array_values(array_filter($both, static fn($hit) => $hit->element instanceof Variant));
        self::assertNotSame([], $variantHits, 'The scenario needs variant hits for ownership to mean anything.');

        foreach ($variantHits as $hit) {
            self::assertInstanceOf(Variant::class, $hit->element);
            self::assertSame($product->id, $hit->element->getOwner()?->id);
            self::assertContains($hit->siteId, [$siteA, $siteB]);
        }

        $this->expectException(InvalidQueryException::class);
        $this->plugin()->getSearch()->search(
            SearchQuery::create($onlySiteA->handle, 'zqxwsites', ['siteId' => $siteB]),
        );
    }

    public function testAPriceRangeAndCountsHoldAcrossASearchOfSeveralSites(): void
    {
        $sites = array_map('intval', Craft::$app->getSites()->getAllSiteIds(true));

        if (count($sites) < 2) {
            self::markTestSkipped('This project has only one site.');
        }

        $index = $this->commerceIndex([[Product::class, 'title'], [Variant::class, 'sku']]);

        $product = $this->createProduct('Zqxwrange Multi Site', [
            ['sku' => 'ZQXWRANGEA', 'price' => 25, 'default' => true],
        ], siteId: $sites[0]);

        foreach ($sites as $siteId) {
            $variant = Product::find()->id($product->id)->siteId($siteId)->status(null)->one();

            if ($variant !== null) {
                $this->indexKeywords($variant);
            }
        }

        $params = ['sites' => $sites, 'limit' => 50, 'facets' => ['elementType', 'siteId']];

        $inRange = $this->plugin()->getSearch()->search(SearchQuery::create($index->handle, 'zqxwrange', $params + [
            'filters' => ['defaultPrice' => ['between' => [10, 50]]],
        ]));

        self::assertContains((int)$product->id, $inRange->getElementIds());

        // Counting follows the same search, so every site it named is represented.
        self::assertEqualsCanonicalizing(
            array_map('strval', $sites),
            array_column($inRange->getFacet('siteId')?->getValues() ?? [], 'value'),
        );

        // A range the product falls outside of keeps it out of the results in every site.
        $outOfRange = $this->plugin()->getSearch()->search(SearchQuery::create($index->handle, 'zqxwrange', $params + [
            'filters' => ['defaultPrice' => ['between' => [100, 200]]],
        ]));

        self::assertNotContains((int)$product->id, $outOfRange->getElementIds());
    }

    /**
     * @param array<string,mixed> $filters
     * @return int[]
     */
    private function ids(array $filters): array
    {
        $ids = array_values(array_unique($this->search(['filters' => $filters])->getElementIds()));
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string,mixed> $filters
     * @return string[]
     */
    private function skus(array $filters): array
    {
        $skus = [];

        foreach ($this->search(['filters' => $filters])->hits as $hit) {
            $element = $hit->element;

            if ($element instanceof Variant) {
                $skus[] = (string)$element->sku;
            }
        }

        $skus = array_values(array_unique($skus));
        sort($skus);

        return $skus;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function search(array $params = []): SearchResult
    {
        return $this->plugin()->getSearch()->search(
            SearchQuery::create($this->index->handle, self::TERM, $params + ['limit' => 50]),
        );
    }

    /**
     * @param array<string,mixed> $filters
     * @return int[]
     */
    private function productIds(SearchIndex $index, string $term, array $filters = []): array
    {
        $ids = [];

        foreach ($this->hitsOf($index, $term, filters: $filters) as $hit) {
            if ($hit->elementType === Product::class) {
                $ids[] = $hit->elementId;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @return string[]
     */
    private function titlesOf(SearchIndex $index, string $term, int $siteId): array
    {
        $titles = [];

        foreach ($this->hitsOf($index, $term, $siteId) as $hit) {
            if ($hit->elementType === Product::class) {
                $titles[] = (string)$hit->element?->title;
            }
        }

        sort($titles);

        return $titles;
    }

    /**
     * @param array<string,mixed> $filters
     * @return \Tahadudhiya\SearchKit\models\SearchHit[]
     */
    private function hitsOf(SearchIndex $index, string $term, ?int $siteId = null, array $filters = []): array
    {
        $params = ['limit' => 50];

        if ($siteId !== null) {
            $params['siteId'] = $siteId;
        }

        if ($filters !== []) {
            $params['filters'] = $filters;
        }

        return $this->plugin()->getSearch()->search(SearchQuery::create($index->handle, $term, $params))->hits;
    }

    /**
     * Works off everything the index owes, so what turns up afterwards was caused by the test.
     */
    private function drain(SearchIndex $index): void
    {
        $this->plugin()->getIndexing()->processPending($index);

        self::assertFalse(
            $this->plugin()->getIndexOperations()->hasPending((int)$index->id),
            'The index still owed work before the change under test.',
        );
    }

    private function defaultVariantOf(Product $product): Variant
    {
        foreach ($product->getVariants() as $variant) {
            if ($variant->isDefault) {
                return $variant;
            }
        }

        self::fail('The product under test has no default variant.');
    }

    /**
     * @return int[]
     */
    private function pendingElementIds(SearchIndex $index): array
    {
        return array_map(
            static fn($operation) => $operation->elementId,
            $this->plugin()->getIndexOperations()->getPending((int)$index->id, 200),
        );
    }

    /**
     * @param RuleAction[] $actions
     */
    private function rule(array $actions): SearchRule
    {
        $rule = new SearchRule([
            'indexId' => $this->index->id,
            'name' => 'Commerce test rule',
            'matchType' => \Tahadudhiya\SearchKit\enums\RuleMatchType::Exact,
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
