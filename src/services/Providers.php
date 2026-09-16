<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Component as ComponentHelper;
use Tahadudhiya\SearchKit\base\SearchProviderInterface;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;

/**
 * Resolves the provider serving an index, and is the only place that knows providers are classes.
 */
class Providers extends Component
{
    public const EVENT_REGISTER_PROVIDER_TYPES = 'registerProviderTypes';

    /** @var array<string,SearchProviderInterface> Provider instances, keyed by index UID. */
    private array $_providers = [];

    /**
     * The provider types offered when choosing one, as Craft does for its own component types.
     * Registration governs what is selectable; any class implementing the interface stays valid.
     *
     * @return string[]
     */
    public function getAllProviderTypes(): array
    {
        $event = new RegisterComponentTypesEvent([
            'types' => [CraftProvider::class],
        ]);

        $this->trigger(self::EVENT_REGISTER_PROVIDER_TYPES, $event);

        return $event->types;
    }

    /**
     * @throws ProviderException if the provider is missing, invalid, or cannot be constructed.
     */
    public function getProviderForIndex(SearchIndex $index): SearchProviderInterface
    {
        // Keyed by the provider too, so changing an index's provider never hands back the old one.
        $key = ($index->uid ?? $index->handle) . ':' . $index->provider;

        return $this->_providers[$key] ??= $this->createProvider($index);
    }

    /**
     * @throws ProviderException
     */
    public function createProvider(SearchIndex $index): SearchProviderInterface
    {
        try {
            ComponentHelper::validateComponentClass($index->provider, SearchProviderInterface::class, true);

            /** @var SearchProviderInterface $provider */
            $provider = Craft::createObject(['class' => $index->provider] + $index->settings);
        } catch (Throwable $e) {
            // Detail is logged rather than thrown, so provider settings never reach a user.
            Craft::error("Could not load the provider for “{$index->handle}”: {$e->getMessage()}", SearchKit::LOG_CATEGORY);
            throw new ProviderException("Could not load the search provider for “{$index->handle}”.", 0, $e);
        }

        return $provider;
    }
}
