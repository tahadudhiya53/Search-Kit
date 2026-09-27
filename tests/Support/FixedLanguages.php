<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use Tahadudhiya\SearchKit\services\Normalization;

/**
 * Stands in for the languages this project's sites are written in, so a search covering sites in
 * genuinely different languages can be exercised where the project has none.
 */
class FixedLanguages extends Normalization
{
    /** @var array<int,string> Language per site ID. */
    public array $languages = [];

    /** @var string|null The language of a site nothing was said about, or null to ask Craft. */
    public ?string $fallback = null;

    public function siteLanguage(?int $siteId): string
    {
        if ($siteId !== null && isset($this->languages[$siteId])) {
            return $this->languages[$siteId];
        }

        return $this->fallback ?? parent::siteLanguage($siteId);
    }
}
