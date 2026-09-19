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
    case PartialMatching = 'partialMatching';
    case PhraseMatching = 'phraseMatching';
    case TermExclusion = 'termExclusion';
    case TermAlternation = 'termAlternation';
    case TypoTolerance = 'typoTolerance';

    /** Leaving named results out of a search entirely, so they cannot appear on any page of it. */
    case ResultExclusion = 'resultExclusion';
}
