<?php

namespace Tahadudhiya\SearchKit\errors;

/**
 * A provider was missing, misconfigured, unreachable, or failed while executing a request.
 */
class ProviderException extends SearchKitException
{
    public function getName(): string
    {
        return 'Search Provider Exception';
    }
}
