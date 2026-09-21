<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\base\FieldInterface;
use craft\behaviors\FieldLayoutBehavior;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as CommercePlugin;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\services\Commerce;
use Tahadudhiya\SearchKit\services\SearchableFields;

/**
 * Real Craft Commerce content for the tests that need it. Everything here goes through Commerce's
 * own services, so nothing is faked and nothing depends on how SearchKit reads it.
 */
abstract class CommerceTestCase extends IntegrationTestCase
{
    /** @var string A searchable custom field this project already has, put on both Commerce layouts. */
    protected const FIELD = 'searchKitTestKeyword';

    /** @var string A relation field this project already has, put on the product layout. */
    protected const RELATION_FIELD = 'navRelatedEntry';

    private ?ProductType $productType = null;

    /** @var Product[] */
    private array $createdProducts = [];

    /** @var Entry[] */
    private array $createdEntries = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $plugin = \Tahadudhiya\SearchKit\SearchKit::getInstance();

        if ($plugin === null || !$plugin->getCommerce()->isInstalled()) {
            self::markTestSkipped('Craft Commerce is not installed.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdProducts as $product) {
            Craft::$app->getElements()->deleteElement($product, true);
        }

        $this->createdProducts = [];

        foreach ($this->createdEntries as $entry) {
            Craft::$app->getElements()->deleteElement($entry, true);
        }

        $this->createdEntries = [];

        if ($this->productType !== null) {
            CommercePlugin::getInstance()->getProductTypes()->deleteProductTypeById((int)$this->productType->id);
            $this->productType = null;
        }

        parent::tearDown();
    }

    /**
     * A product type whose product and variant layouts both carry the searchable field under test.
     */
    protected function productType(): ProductType
    {
        if ($this->productType !== null) {
            return $this->productType;
        }

        $handle = 'skTest' . bin2hex(random_bytes(4));
        $type = new ProductType();
        $type->name = $handle;
        $type->handle = $handle;
        $type->hasDimensions = true;
        $type->hasVariantTitleField = true;
        $type->maxVariants = null;
        // Commerce keeps both layouts on field-layout behaviours rather than plain setters.
        $productLayout = $type->getBehavior('productFieldLayout');
        $variantLayout = $type->getBehavior('variantFieldLayout');

        if (!$productLayout instanceof FieldLayoutBehavior || !$variantLayout instanceof FieldLayoutBehavior) {
            self::fail('Commerce did not expose its product and variant field layout behaviours.');
        }

        $productLayout->setFieldLayout($this->layoutFor(Product::class));
        $variantLayout->setFieldLayout($this->layoutFor(Variant::class));

        $siteSettings = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[$site->id] = new ProductTypeSite(['siteId' => $site->id, 'hasUrls' => false]);
        }

        $type->setSiteSettings($siteSettings);

        if (!CommercePlugin::getInstance()->getProductTypes()->saveProductType($type)) {
            self::fail('Could not create a Commerce product type: ' . implode(' ', $type->getErrorSummary(true)));
        }

        return $this->productType = $type;
    }

    private function layoutFor(string $elementType): FieldLayout
    {
        $elements = [new CustomField($this->projectField(static::FIELD))];

        // Products also carry the relation field, so relationship filtering has something real.
        if ($elementType === Product::class) {
            $elements[] = new CustomField($this->projectField(static::RELATION_FIELD));
        }

        $layout = new FieldLayout(['type' => $elementType]);
        $layout->setTabs([['name' => 'Content', 'elements' => $elements]]);

        return $layout;
    }

    private function projectField(string $handle): FieldInterface
    {
        $field = Craft::$app->getFields()->getFieldByHandle($handle);

        if ($field === null) {
            self::markTestSkipped('This project has no “' . $handle . '” field.');
        }

        return $field;
    }

    /**
     * An entry for a product to be related to, in a section this project already has.
     */
    protected function createRelatedEntry(string $title): Entry
    {
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            foreach ($section->getSiteSettings() as $siteSettings) {
                if ((int)$siteSettings->siteId !== $this->primarySiteId()) {
                    continue;
                }

                $entry = new Entry();
                $entry->sectionId = $section->id;
                $entry->typeId = $section->getEntryTypes()[0]->id;
                $entry->siteId = $this->primarySiteId();
                $entry->title = $title;

                if (!Craft::$app->getElements()->saveElement($entry)) {
                    self::fail('Could not create a related entry: ' . implode(' ', $entry->getErrorSummary(true)));
                }

                $this->createdEntries[] = $entry;

                return $entry;
            }
        }

        self::markTestSkipped('This project has no section covering the primary site.');
    }

    /**
     * @param array<int,array<string,mixed>> $variants Each: sku, price, default, tracked, stock, keyword.
     * @param array<string,mixed> $productValues
     */
    protected function createProduct(
        string $title,
        array $variants,
        array $productValues = [],
        ?int $siteId = null,
        bool $enabled = true,
    ): Product {
        $product = new Product();
        $product->typeId = $this->productType()->id;
        $product->siteId = $siteId ?? (int)Craft::$app->getSites()->getPrimarySite()->id;
        $product->title = $title;
        $product->enabled = $enabled;
        $product->setFieldValues($productValues);

        $models = [];

        foreach ($variants as $spec) {
            $variant = new Variant();
            $variant->title = $title . ' ' . $spec['sku'];
            $variant->sku = (string)$spec['sku'];
            $variant->basePrice = (float)($spec['price'] ?? 0);
            $variant->isDefault = (bool)($spec['default'] ?? false);
            $variant->inventoryTracked = (bool)($spec['tracked'] ?? false);
            $variant->setFieldValues([static::FIELD => $spec['keyword'] ?? '']);
            $models[] = $variant;
        }

        $product->setVariants($models);

        if (!Craft::$app->getElements()->saveElement($product)) {
            self::fail('Could not create a Commerce product: ' . implode(' ', $product->getErrorSummary(true)));
        }

        $this->createdProducts[] = $product;

        foreach ($product->getVariants() as $i => $variant) {
            if (isset($variants[$i]['stock'])) {
                CommercePlugin::getInstance()->getInventory()
                    ->updatePurchasableInventoryLevel($variant, (int)$variants[$i]['stock']);
            }
        }

        $this->indexKeywords($product);

        return $product;
    }

    /**
     * Craft indexes keywords on save; doing it here makes the tests independent of when.
     */
    protected function indexKeywords(Product $product): void
    {
        Craft::$app->getSearch()->indexElementAttributes($product);

        foreach ($product->getVariants() as $variant) {
            Craft::$app->getSearch()->indexElementAttributes($variant);
        }
    }

    /**
     * A saved index over Commerce content, which needs more than one handle per element type.
     *
     * @param array<int,array{0:string,1:string}> $fields Element type and handle pairs.
     */
    protected function commerceIndex(array $fields, ?int $siteId = null): SearchIndex
    {
        // The product type carries the Commerce field layouts, so it has to exist before anything
        // asks what a product or variant can be indexed on.
        $this->productType();

        $index = $this->newIndex(CraftProvider::class);
        $index->siteId = $siteId;
        $this->persistIndex($index);

        $searchable = array_map(
            static fn(array $pair) => new SearchableField([
                'elementType' => $pair[0],
                'handle' => $pair[1],
                'weight' => 5,
            ]),
            $fields,
        );

        self::assertTrue(
            $this->freshSearchableFields()->saveFieldsForIndex($index, $searchable),
            implode(' ', array_merge(...array_map(static fn($f) => $f->getErrorSummary(true), $searchable))),
        );

        return $index;
    }

    /**
     * A service that has memoized no field layouts, since these tests create them as they run.
     */
    protected function freshSearchableFields(): SearchableFields
    {
        return new SearchableFields();
    }

    protected function primarySiteId(): int
    {
        return (int)Craft::$app->getSites()->getPrimarySite()->id;
    }

    protected function commerce(): Commerce
    {
        return $this->plugin()->getCommerce();
    }
}
