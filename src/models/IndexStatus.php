<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use DateTime;

/**
 * What an administrator needs to know about an index: whether it is serving, and what it owes.
 */
class IndexStatus extends Model
{
    public string $indexHandle = '';
    public bool $enabled = true;
    public int $pending = 0;
    public int $failed = 0;
    public ?DateTime $dateLastIndexed = null;

    /** @var bool The index owes a rebuild, so what the provider holds may be stale. */
    public bool $rebuildRequired = false;

    /** @var ProviderStatus|null The provider's own health, or null when it could not be loaded. */
    public ?ProviderStatus $provider = null;

    public bool $canIndex = false;
    public bool $canDelete = false;

    /**
     * Only true when the index is serving, there is nothing outstanding, nothing failed, no rebuild
     * owed, and a provider that says it can serve. Anything less is reported as it is.
     */
    public function isHealthy(): bool
    {
        return $this->enabled
            && $this->failed === 0
            && $this->pending === 0
            && !$this->rebuildRequired
            && ($this->provider === null || $this->provider->available);
    }
}
