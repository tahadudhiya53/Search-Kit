<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * A result people opened, and how often. It names the result, never whoever opened it.
 */
class ClickedResult extends Model
{
    public int $elementId = 0;

    /** @var string The element's own class, as the click recorded it. */
    public string $elementType = '';

    /** @var int The site the result was opened in. */
    public int $siteId = 0;

    public int $clicks = 0;

    /** @var float Mean place in the results this was opened from, counted from one. */
    public float $averagePosition = 0.0;

    /** @var string|null What the result is called now, or null for one that can no longer be read. */
    public ?string $label = null;

    /** @var string|null Where an administrator can open it, when they are allowed to. */
    public ?string $cpEditUrl = null;

    public function isResolved(): bool
    {
        return $this->label !== null && $this->label !== '';
    }
}
