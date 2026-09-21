<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Component as ComponentHelper;
use Tahadudhiya\SearchKit\base\SearchProviderInterface;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\providers\MeilisearchProvider;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;
use yii\base\Model;

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
            'types' => [CraftProvider::class, MeilisearchProvider::class],
        ]);

        $this->trigger(self::EVENT_REGISTER_PROVIDER_TYPES, $event);

        return $event->types;
    }

    /**
     * Puts an index's provider settings to the provider itself, since only it knows what it needs
     * to reach its engine. Errors land on the index, so a bad address is a validation failure
     * rather than something discovered the next time somebody searches.
     */
    public function validateSettings(SearchIndex $index): bool
    {
        try {
            $provider = $this->createProvider($index);
        } catch (ProviderException $e) {
            $index->addError('settings', $e->getMessage());
            return false;
        }

        // The interface only promises a provider; validation belongs to the model every provider
        // in practice is, and one that is not has no settings to put to it.
        if (!$provider instanceof Model || $provider->validate()) {
            return true;
        }

        foreach ($provider->getErrorSummary(true) as $error) {
            $index->addError('settings', $error);
        }

        return false;
    }

    /**
     * @throws ProviderException if the provider is missing, invalid, or cannot be constructed.
     */
    public function getProviderForIndex(SearchIndex $index): SearchProviderInterface
    {
        // Keyed by the provider too, so changing an index's provider never hands back the old one.
        $key = $this->identity($index) . ':' . $index->provider;

        return $this->_providers[$key] ??= $this->createProvider($index);
    }

    /**
     * Drops whatever is held for an index. A provider is built from the index's settings, so an
     * instance outliving a configuration change would go on reaching the engine the settings it
     * replaced described.
     */
    public function forgetProvider(SearchIndex $index): void
    {
        $prefix = $this->identity($index) . ':';

        foreach (array_keys($this->_providers) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->_providers[$key]);
            }
        }
    }

    /**
     * What identifies an index across a configuration change. A new index has no UID until it is
     * saved, so its handle stands in until then.
     */
    private function identity(SearchIndex $index): string
    {
        return $index->uid ?? $index->handle;
    }

    /**
     * @throws ProviderException
     */
    public function createProvider(SearchIndex $index): SearchProviderInterface
    {
        try {
            return $this->createProviderOfType($index->provider, $index->settings);
        } catch (Throwable $e) {
            // Detail is logged rather than thrown, so provider settings never reach a user.
            Craft::error("Could not load the provider for “{$index->handle}”: {$e->getMessage()}", SearchKit::LOG_CATEGORY);
            throw new ProviderException("Could not load the search provider for “{$index->handle}”.", 0, $e);
        }
    }

    /**
     * One provider of a named type, configured with the settings given. This is the only place a
     * provider is constructed, so nothing else has to know they are classes.
     *
     * @param array<string,mixed> $settings
     */
    public function createProviderOfType(string $type, array $settings = []): SearchProviderInterface
    {
        ComponentHelper::validateComponentClass($type, SearchProviderInterface::class, true);

        /** @var SearchProviderInterface $provider */
        $provider = Craft::createObject(['class' => $type] + $settings);

        return $provider;
    }
}
