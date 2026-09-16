<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\ElementInterface;
use craft\base\Model;

/**
 * What SearchKit hands a provider: one element, in one site, reduced to the configured searchable
 * values and their weights. It carries no provider request structure of any kind.
 */
class SearchDocument extends Model
{
    public int $elementId = 0;
    public int $siteId = 0;

    /** @var string The element class this document was built from. */
    public string $elementType = '';

    public string $indexHandle = '';

    /**
     * The element this document was built from, kept as source context for providers whose engine
     * is Craft itself. It is not document data: only the fields below are searchable content.
     */
    private ?ElementInterface $_source = null;

    /** @var array<string,string> Searchable text, keyed by field handle. */
    private array $_fields = [];

    /** @var array<string,int> Relevance weights, keyed by field handle. */
    private array $_weights = [];

    /**
     * A document identifying an element without its content, which is all a deletion needs.
     */
    public static function forDeletion(int $elementId, int $siteId, string $elementType, string $indexHandle): self
    {
        return new self([
            'elementId' => $elementId,
            'siteId' => $siteId,
            'elementType' => $elementType,
            'indexHandle' => $indexHandle,
        ]);
    }

    public function getSource(): ?ElementInterface
    {
        return $this->_source;
    }

    public function setSource(?ElementInterface $source): void
    {
        $this->_source = $source;
    }

    public function setField(string $handle, string $value, int $weight = SearchableField::DEFAULT_WEIGHT): void
    {
        $this->_fields[$handle] = $value;
        $this->_weights[$handle] = $weight;
    }

    /**
     * @return array<string,string>
     */
    public function getFields(): array
    {
        return $this->_fields;
    }

    /**
     * @return string[]
     */
    public function getFieldHandles(): array
    {
        return array_keys($this->_fields);
    }

    public function getValue(string $handle): ?string
    {
        return $this->_fields[$handle] ?? null;
    }

    /**
     * Weights stay plain handle-to-integer pairs, so each provider maps them to its own syntax.
     *
     * @return array<string,int>
     */
    public function getWeights(): array
    {
        return $this->_weights;
    }

    public function getWeight(string $handle): ?int
    {
        return $this->_weights[$handle] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->_fields === [];
    }
}
