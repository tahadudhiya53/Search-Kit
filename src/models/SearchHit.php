<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\ElementInterface;
use craft\base\Model;
use Twig\Markup;

/**
 * One normalized match. Providers fill in what they can; everything optional stays null or empty.
 */
class SearchHit extends Model
{
    public int $elementId;
    public ?int $siteId = null;
    public ?string $elementType = null;
    /** @var float The provider's own relevance score, left exactly as it was reported. */
    public float $score = 0.0;

    /** @var float How far search rules moved this result, kept apart from the provider's score. */
    public float $scoreAdjustment = 0.0;

    /** @var bool Whether a rule placed this result at a position of its own. */
    public bool $pinned = false;

    /** @var bool Whether a rule put this result in the search rather than the search finding it. */
    public bool $promoted = false;

    /** @var array<int,array<string,mixed>> What search rules did to this result, for explaining it. */
    public array $ruleEffects = [];

    /** @var string[] Handles of the fields the provider matched on. */
    public array $matchedFields = [];

    /** @var array<string,string> Plain-text excerpts of the matched text, keyed by field handle. */
    public array $snippets = [];

    /** @var array<string,string> The same excerpts with matched terms marked, keyed by field handle. */
    public array $highlights = [];

    /** @var array<string,mixed> Diagnostic: provider-specific detail, never part of the contract. */
    public array $providerData = [];

    public ?ElementInterface $element = null;

    /**
     * The score this result was ranked by: what the provider scored it, plus what the rules moved it.
     */
    public function getFinalScore(): float
    {
        return $this->score + $this->scoreAdjustment;
    }

    /**
     * Whether a rule placed this result rather than the search matching it.
     */
    public function wasPlaced(): bool
    {
        return $this->pinned || $this->promoted;
    }

    /**
     * A plain-text excerpt, from the named field or the best one available.
     */
    public function getSnippet(?string $field = null): ?string
    {
        return $this->pick($this->snippets, $field);
    }

    /**
     * The same excerpt with matched terms wrapped in `<mark>`, ready to print in a template.
     */
    public function getHighlight(?string $field = null): ?Markup
    {
        $highlight = $this->pick($this->highlights, $field);

        // Built and escaped here as UTF-8, so it is safe to print without escaping it again.
        return $highlight !== null ? new Markup($highlight, 'UTF-8') : null;
    }

    public function hasHighlights(): bool
    {
        return $this->highlights !== [];
    }

    /**
     * The element type in the same vocabulary a filter uses — `entry`, `category`, `asset`, `user`
     * — rather than a class name, so what an API hands back can be asked for again.
     */
    public function getElementTypeHandle(): ?string
    {
        if ($this->elementType === null || !is_subclass_of($this->elementType, ElementInterface::class)) {
            return $this->elementType;
        }

        return $this->elementType::refHandle() ?? $this->elementType;
    }

    /**
     * @param array<string,string> $values
     */
    private function pick(array $values, ?string $field): ?string
    {
        if ($field !== null) {
            return $values[$field] ?? null;
        }

        // Fields arrive heaviest first, so the first one is the most relevant excerpt available.
        return $values === [] ? null : reset($values);
    }
}
