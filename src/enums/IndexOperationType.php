<?php

namespace Tahadudhiya\SearchKit\enums;

/**
 * What an outstanding indexing operation will do to the provider's copy of an element.
 */
enum IndexOperationType: string
{
    case Index = 'index';
    case Delete = 'delete';

    public function capability(): ProviderCapability
    {
        return match ($this) {
            self::Index => ProviderCapability::Indexing,
            self::Delete => ProviderCapability::Deleting,
        };
    }
}
