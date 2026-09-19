<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use craft\validators\HandleValidator;
use DateTime;
use Tahadudhiya\SearchKit\base\SearchProviderInterface;

/**
 * An administrator-managed search index: what it is called, who serves it, and how it is configured.
 */
class SearchIndex extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';

    /** @var string The provider component class serving this index. */
    public string $provider = '';

    public bool $enabled = true;

    /** @var array<string,mixed> Provider settings. Credentials belong in environment variables. */
    public array $settings = [];

    /** @var int|null The only site this index covers, or null to cover every site. */
    public ?int $siteId = null;

    /** @var DateTime|null When this index last finished a run with nothing left outstanding. */
    public ?DateTime $dateLastIndexed = null;

    /**
     * Which generation of this index's configuration the row represents. A rebuild remembers the
     * generation it started against and may only settle that one.
     */
    public int $configurationVersion = 1;

    /**
     * Whether the index owes a rebuild: its configuration changed, or its last rebuild left work
     * behind. Cleared only once a rebuild has covered the current configuration with nothing left.
     */
    public bool $rebuildRequired = false;

    /** @var bool A rebuild walked this configuration but did not finish cleanly. */
    public bool $rebuildPending = false;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** @var SearchableField[]|null Hydrated on demand by the service that resolves the index. */
    private ?array $_fields = null;

    /** @var SearchSettings|null How this index treats the text it is searched with. */
    private ?SearchSettings $_searchSettings = null;

    /** @var AnalyticsSettings|null What this index records about its searches, and for how long. */
    private ?AnalyticsSettings $_analyticsSettings = null;

    public function getSearchSettings(): SearchSettings
    {
        return $this->_searchSettings ??= new SearchSettings();
    }

    /**
     * @param SearchSettings|array<string,mixed> $settings
     */
    public function setSearchSettings(SearchSettings|array $settings): void
    {
        $this->_searchSettings = $settings instanceof SearchSettings
            ? $settings
            : SearchSettings::fromConfig($settings);
    }

    public function getAnalyticsSettings(): AnalyticsSettings
    {
        return $this->_analyticsSettings ??= new AnalyticsSettings();
    }

    /**
     * @param AnalyticsSettings|array<string,mixed> $settings
     */
    public function setAnalyticsSettings(AnalyticsSettings|array $settings): void
    {
        $this->_analyticsSettings = $settings instanceof AnalyticsSettings
            ? $settings
            : AnalyticsSettings::fromConfig($settings);
    }

    public function coversAllSites(): bool
    {
        return $this->siteId === null;
    }

    public function coversSite(int $siteId): bool
    {
        return $this->siteId === null || $this->siteId === $siteId;
    }

    /**
     * @return SearchableField[]
     */
    public function getFields(): array
    {
        return $this->_fields ?? [];
    }

    /**
     * @param SearchableField[] $fields
     */
    public function setFields(array $fields): void
    {
        $this->_fields = array_values($fields);
    }

    public function fieldsAreLoaded(): bool
    {
        return $this->_fields !== null;
    }

    /**
     * @return SearchableField[]
     */
    public function getEnabledFields(?string $elementType = null): array
    {
        return array_values(array_filter(
            $this->getFields(),
            static fn(SearchableField $field) => $field->enabled
                && ($elementType === null || $field->elementType === $elementType),
        ));
    }

    /**
     * Field handle => weight, the provider-independent form of relevance weighting.
     *
     * @return array<string,int>
     */
    public function getFieldWeights(?string $elementType = null): array
    {
        $weights = [];

        foreach ($this->getEnabledFields($elementType) as $field) {
            $weights[$field->handle] = max($weights[$field->handle] ?? 0, $field->weight);
        }

        return $weights;
    }

    /**
     * The element types this index covers, derived from its searchable fields.
     *
     * @return string[]
     */
    public function getElementTypes(): array
    {
        return array_values(array_unique(array_map(
            static fn(SearchableField $field) => $field->elementType,
            $this->getEnabledFields(),
        )));
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'provider'], 'required'],
            [['name', 'handle', 'provider'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class],
            [['provider'], 'validateProvider'],
            [['siteId', 'id'], 'integer', 'min' => 1],
        ];
    }

    /**
     * Search behaviour and analytics are models of their own, so they are validated alongside the
     * index they belong to.
     */
    public function afterValidate(): void
    {
        if (!$this->getSearchSettings()->validate()) {
            foreach ($this->getSearchSettings()->getErrorSummary(true) as $error) {
                $this->addError('searchSettings', $error);
            }
        }

        if (!$this->getAnalyticsSettings()->validate()) {
            foreach ($this->getAnalyticsSettings()->getErrorSummary(true) as $error) {
                $this->addError('analyticsSettings', $error);
            }
        }

        parent::afterValidate();
    }

    public function validateProvider(string $attribute): void
    {
        if (!is_subclass_of($this->provider, SearchProviderInterface::class)) {
            $this->addError($attribute, "“{$this->provider}” is not a search provider.");
        }
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : $this->handle;
    }
}
