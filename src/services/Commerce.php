<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\base\ElementInterface;
use craft\events\ElementEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\ArrayHelper;
use craft\services\Elements;
use Tahadudhiya\SearchKit\events\RegisterFilterCriteriaEvent;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * Craft Commerce support, kept wholly on this side of the boundary: nothing in SearchKit's core
 * names Commerce, and everything here is inert unless Commerce is installed.
 */
class Commerce extends Component
{
    public const PLUGIN_HANDLE = 'commerce';

    /** @var string Named as a string, so nothing here can autoload Commerce that is not installed. */
    public const PRODUCT = 'craft\commerce\elements\Product';

    public const VARIANT = 'craft\commerce\elements\Variant';

    /** @var int The Commerce line this was written against — the only one Craft 5 can run. */
    public const SUPPORTED_MAJOR_VERSION = 5;

    /**
     * Product criteria offered to filters, verified against Commerce 5.7.4's own ProductQuery. A
     * product's type is `type` or `typeId` and a category is `relatedTo`, which every element type
     * already offers, so neither is repeated here.
     */
    private const PRODUCT_CRITERIA = [
        'defaultSku', 'defaultPrice', 'defaultWidth', 'defaultHeight', 'defaultLength', 'defaultWeight',
    ];

    /**
     * Variant criteria, verified against Commerce 5.7.4's own VariantQuery. Product type is `typeId`,
     * which VariantQuery answers for the owning product.
     */
    private const VARIANT_CRITERIA = [
        'sku', 'price', 'stock', 'hasStock', 'hasUnlimitedStock', 'inventoryTracked',
        'availableForPurchase', 'isDefault', 'productId',
        'minQty', 'maxQty', 'width', 'height', 'length', 'weight',
    ];

    private ?bool $_installed = null;

    /** @var array<string,string[]> Criteria resolved against the installed query, per element type. */
    private array $_criteria = [];

    private ?Indexes $_indexes = null;

    private ?SearchableFields $_searchableFields = null;

    private ?Indexing $_indexing = null;

    public function isInstalled(): bool
    {
        return $this->_installed ??= Craft::$app->getPlugins()->getPlugin(self::PLUGIN_HANDLE) !== null
            && $this->getElementTypes() !== [];
    }

    /**
     * The Commerce element types SearchKit can index — asked of the installed Commerce rather than
     * assumed, so a version that does not provide one simply does not offer it.
     *
     * @return string[]
     */
    public function getElementTypes(): array
    {
        return array_values(array_filter(
            [self::PRODUCT, self::VARIANT],
            static fn(string $class) => is_subclass_of($class, ElementInterface::class),
        ));
    }

    /**
     * The installed Commerce's major version, or 0 when Commerce is not installed.
     */
    public function getMajorVersion(): int
    {
        $plugin = Craft::$app->getPlugins()->getPlugin(self::PLUGIN_HANDLE);

        return $plugin !== null ? (int)explode('.', $plugin->getVersion())[0] : 0;
    }

    /**
     * Wires Commerce into the indexing, configuration and filtering SearchKit already has. Rules,
     * insights, the debugger and both APIs need nothing: they work on element types, whatever
     * defines them.
     */
    public function register(): void
    {
        if (!$this->isInstalled()) {
            return;
        }

        $this->registerElementTypes();
        $this->registerFilterCriteria();
        $this->registerVariantSync();
    }

    /**
     * The criteria a filter may name on a Commerce element type, settled against the query the
     * installed Commerce actually defines. A criterion it does not define is never offered, and is
     * then refused by the provider rather than quietly dropped.
     *
     * @return string[]
     */
    public function getCriteria(string $elementType): array
    {
        if (isset($this->_criteria[$elementType])) {
            return $this->_criteria[$elementType];
        }

        // The classes can be present while the plugin is not, and then Commerce is not there.
        if (!$this->isInstalled()) {
            return $this->_criteria[$elementType] = [];
        }

        $candidates = match ($elementType) {
            self::PRODUCT => self::PRODUCT_CRITERIA,
            self::VARIANT => self::VARIANT_CRITERIA,
            default => [],
        };

        $query = is_subclass_of($elementType, ElementInterface::class)
            ? call_user_func([$elementType, 'find'])
            : null;

        if ($candidates === [] || !is_object($query)) {
            return $this->_criteria[$elementType] = [];
        }

        return $this->_criteria[$elementType] = array_values(array_filter(
            $candidates,
            static fn(string $criterion) => method_exists($query, $criterion),
        ));
    }

    private function registerElementTypes(): void
    {
        Event::on(
            SearchableFields::class,
            SearchableFields::EVENT_REGISTER_INDEXABLE_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types = array_merge($event->types, $this->getElementTypes());
            },
        );
    }

    private function registerFilterCriteria(): void
    {
        Event::on(
            CraftProvider::class,
            CraftProvider::EVENT_REGISTER_FILTER_CRITERIA,
            function(RegisterFilterCriteriaEvent $event) {
                $event->criteria = array_merge($event->criteria, $this->getCriteria($event->elementType));
            },
        );
    }

    /**
     * A product's own keywords can be drawn from its variants, so a variant change can be a product
     * change — but only where an index searches a product attribute, which is decided per change.
     */
    private function registerVariantSync(): void
    {
        $handler = function(ElementEvent $event) {
            $this->reindexOwningProduct($event->element);
        };

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $handler);
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, $handler);
        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, $handler);
    }

    /**
     * Saving content must never fail because of this, so nothing here is allowed to escape. What it
     * hands to indexing is an ordinary element change, which keeps the queue's own error handling.
     */
    private function reindexOwningProduct(ElementInterface $element): void
    {
        try {
            // Only the default variant's values reach a product's document, so nothing else can
            // make one stale — and asking that first is what keeps a variant save loading nothing.
            if (!is_a($element, self::VARIANT) || !$this->isDefaultVariant($element)) {
                return;
            }

            if (!$this->productDependsOnVariants()) {
                return;
            }

            $product = $this->ownerProduct($element);

            if ($product !== null) {
                $this->getIndexing()->handleElementSave($product);
            }
        } catch (Throwable $e) {
            Craft::error(
                "Could not follow variant {$element->id} to its product: {$e->getMessage()}",
                SearchKit::LOG_CATEGORY,
            );
        }
    }

    /**
     * Whether this is the variant a product takes its own values from. A variant that is ceasing to
     * be the default is not followed: the one replacing it is saved too, and carries the flag.
     */
    private function isDefaultVariant(ElementInterface $variant): bool
    {
        // Read by name, so this file still needs no compile-time reference to a Commerce class.
        return $variant->canGetProperty('isDefault') && (bool)ArrayHelper::getValue($variant, 'isDefault');
    }

    /**
     * Commerce has renamed a variant's product to its owner, so both are asked for rather than
     * pinning this to one Commerce version.
     */
    private function ownerProduct(ElementInterface $variant): ?ElementInterface
    {
        foreach (['getOwner', 'getProduct'] as $method) {
            if (!method_exists($variant, $method)) {
                continue;
            }

            $product = $variant->$method();

            if ($product instanceof ElementInterface && is_a($product, self::PRODUCT)) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Whether a product's document draws on its default variant at all. This mirrors how that
     * document is built: a handle is only read as an attribute when no custom field on the product's
     * own layout claims it, and a product's own searchable attributes are the values Commerce fills
     * from that variant — `title` and `slug` are Craft's, and are never among them. Answered afresh
     * each time, because an index's configuration can change while a request is running.
     */
    private function productDependsOnVariants(): bool
    {
        $derived = $this->productSearchableAttributes();

        foreach ($this->getIndexes()->getAllIndexes() as $index) {
            if (!$index->enabled) {
                continue;
            }

            $this->getSearchableFields()->attachFields($index);

            foreach ($index->getEnabledFields(self::PRODUCT) as $field) {
                if (in_array($field->handle, $derived, true) && !$this->isCustomField(self::PRODUCT, $field->handle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * What Commerce declares a product's searchable attributes to be — `defaultSku` and `sku` on
     * Commerce 5, both of which it fills from the default variant. Asked of the installed version,
     * so an attribute it stops declaring stops being followed.
     *
     * @return string[]
     */
    private function productSearchableAttributes(): array
    {
        if (!is_subclass_of(self::PRODUCT, ElementInterface::class)) {
            return [];
        }

        return call_user_func([self::PRODUCT, 'searchableAttributes']);
    }

    private function isCustomField(string $elementType, string $handle): bool
    {
        foreach (Craft::$app->getFields()->getLayoutsByType($elementType) as $layout) {
            if ($layout->getFieldByHandle($handle) !== null) {
                return true;
            }
        }

        return false;
    }

    public function setIndexes(Indexes $indexes): void
    {
        $this->_indexes = $indexes;
    }

    public function getIndexes(): Indexes
    {
        return $this->_indexes ??= $this->plugin()->getIndexes();
    }

    public function setSearchableFields(SearchableFields $searchableFields): void
    {
        $this->_searchableFields = $searchableFields;
    }

    public function getSearchableFields(): SearchableFields
    {
        return $this->_searchableFields ??= $this->plugin()->getSearchableFields();
    }

    public function setIndexing(Indexing $indexing): void
    {
        $this->_indexing = $indexing;
    }

    public function getIndexing(): Indexing
    {
        return $this->_indexing ??= $this->plugin()->getIndexing();
    }

    private function plugin(): SearchKit
    {
        $plugin = SearchKit::getInstance();

        if ($plugin === null) {
            throw new InvalidConfigException('Search Kit is not installed or is disabled.');
        }

        return $plugin;
    }
}
