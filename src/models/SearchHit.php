<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\ElementInterface;
use craft\base\Model;

/**
 * One normalized match. Providers fill in what they can; everything optional stays null or empty.
 */
class SearchHit extends Model
{
    public int $elementId;
    public ?int $siteId = null;
    public ?string $elementType = null;
    public float $score = 0.0;

    /** @var string[] Handles of the fields the provider matched on. */
    public array $matchedFields = [];

    /** @var array<string,string[]> Highlighted snippets, keyed by field handle. */
    public array $highlights = [];

    /** @var array<string,mixed> Provider-specific detail, kept out of SearchKit's own concepts. */
    public array $providerData = [];

    public ?ElementInterface $element = null;
}
