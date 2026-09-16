<?php

namespace Tahadudhiya\SearchKit\enums;

/**
 * What a provider can do, declared up front so core never branches on a provider's identity.
 */
enum ProviderCapability: string
{
    case Search = 'search';
    case Indexing = 'indexing';
    case Deleting = 'deleting';
    case Rebuilding = 'rebuilding';
    case Filtering = 'filtering';
    case Sorting = 'sorting';
    case Highlighting = 'highlighting';
    case FieldWeighting = 'fieldWeighting';
}
