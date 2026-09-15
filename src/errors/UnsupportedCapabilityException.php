<?php

namespace Tahadudhiya\SearchKit\errors;

use Tahadudhiya\SearchKit\enums\ProviderCapability;

class UnsupportedCapabilityException extends ProviderException
{
    public static function for(string $providerName, ProviderCapability $capability): self
    {
        return new self("The $providerName search provider does not support {$capability->value}.");
    }

    public function getName(): string
    {
        return 'Unsupported Provider Capability';
    }
}
