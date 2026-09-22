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

    /** @var string|null The query these clicks came from, where the reading is one query at a time. */
    public ?string $query = null;

    /** @var float Mean place in the results this was opened from, counted from one. */
    public float $averagePosition = 0.0;

    /** @var string|null What the result is called now, or null for one that can no longer be read. */
    public ?string $label = null;

    /** @var string|null Where an administrator can open it, when they are allowed to. */
    public ?string $cpEditUrl = null;

    /**
     * One grouped row of clicks. `clickSiteId` is the click's own site, aliased apart from the
     * searching site the event carries, which is what both readings of clicks select it as.
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self([
            'query' => isset($row['normalizedQuery']) ? (string)$row['normalizedQuery'] : null,
            'elementId' => (int)$row['elementId'],
            'elementType' => (string)$row['elementType'],
            'siteId' => (int)$row['clickSiteId'],
            'clicks' => (int)$row['clicks'],
            'averagePosition' => round((float)$row['averagePosition'], 2),
        ]);
    }

    public function isResolved(): bool
    {
        return $this->label !== null && $this->label !== '';
    }
}
