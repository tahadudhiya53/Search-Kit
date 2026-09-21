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

    /** Counting how the whole result set divides up by a field, so a filter can be offered for it. */
    case Faceting = 'faceting';

    /**
     * What this means to somebody configuring an index, rather than what it is called in code.
     */
    public function label(): string
    {
        return match ($this) {
            self::Search => 'Running searches',
            self::Indexing => 'Indexing content',
            self::Deleting => 'Removing indexed content',
            self::Rebuilding => 'Rebuilding the index',
            self::Filtering => 'Filtering results',
            self::Sorting => 'Sorting results',
            self::Faceting => 'Counting results by a field',
            self::Highlighting => 'Highlighting matches',
            self::FieldWeighting => 'Weighting fields',
            self::PartialMatching => 'Matching part of a word',
            self::PhraseMatching => 'Matching a phrase',
            self::TermExclusion => 'Excluding a term',
            self::TermAlternation => 'Alternative terms and synonyms',
            self::TypoTolerance => 'Tolerating typos',
            self::ResultExclusion => 'Leaving named results out',
        };
    }
}
