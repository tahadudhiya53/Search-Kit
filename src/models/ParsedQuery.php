<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * What a raw query became: normalized text, its terms, and what the pipeline did to them. This is
 * the only form a provider is asked to run, and the form a ranking explanation is built from.
 */
class ParsedQuery extends Model
{
    /** @var string The text exactly as it was given. */
    public string $raw = '';

    /** @var string The same text normalized, before it was split into terms. */
    public string $normalized = '';

    /** @var string[] Words dropped as too common to narrow anything down. */
    public array $removedStopWords = [];

    /**
     * @var string[] The languages this text was read in — one per language the sites being searched
     * are written in. More than one means no single language may be claimed for the search.
     */
    public array $languages = [];

    /**
     * @var string[] Expansions that hold in some of the sites being searched but not all of them,
     * so applying them would have widened the search beyond the site they were written for.
     */
    public array $withheldSynonyms = [];

    /** @var bool Whether any term was replaced by a correction of it. */
    public bool $corrected = false;

    /** @var QueryTerm[] */
    private array $_terms = [];

    /**
     * Terms are objects, so a copy of a query has to be a copy of them too — a correction works on
     * one without changing the query it was made from.
     */
    public function __clone(): void
    {
        $this->_terms = array_map(static fn(QueryTerm $term) => clone $term, $this->_terms);
    }

    /**
     * @return QueryTerm[]
     */
    public function getTerms(): array
    {
        return $this->_terms;
    }

    /**
     * @param QueryTerm[] $terms
     */
    public function setTerms(array $terms): void
    {
        $this->_terms = array_values($terms);
    }

    /**
     * The terms a result has to match.
     *
     * @return QueryTerm[]
     */
    public function getRequiredTerms(): array
    {
        return array_values(array_filter($this->_terms, static fn(QueryTerm $term) => !$term->excluded));
    }

    /**
     * @return QueryTerm[]
     */
    public function getExcludedTerms(): array
    {
        return array_values(array_filter($this->_terms, static fn(QueryTerm $term) => $term->excluded));
    }

    /**
     * Every text that can mark a match, which is what an excerpt is highlighted from.
     *
     * @return string[]
     */
    public function getTokens(): array
    {
        $tokens = [];

        foreach ($this->getRequiredTerms() as $term) {
            foreach ($term->getTexts() as $text) {
                $tokens[] = $text;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Whether the sites being searched are written in more than one language, which is what stops
     * any one of them standing in for the search.
     */
    public function isMultiLingual(): bool
    {
        return count($this->languages) > 1;
    }

    public function isEmpty(): bool
    {
        return $this->getRequiredTerms() === [];
    }

    public function hasPhrases(): bool
    {
        return $this->anyTerm(static fn(QueryTerm $term) => $term->phrase);
    }

    public function hasExclusions(): bool
    {
        return $this->getExcludedTerms() !== [];
    }

    public function hasAlternatives(): bool
    {
        return $this->anyTerm(static fn(QueryTerm $term) => $term->hasAlternatives());
    }

    public function hasPartialMatching(): bool
    {
        return $this->anyTerm(static fn(QueryTerm $term) => $term->partial->matchesRight() || $term->partial->matchesLeft());
    }

    /**
     * The query written back out from its terms, which is how a correction is shown to a user.
     */
    public function getText(): string
    {
        return implode(' ', array_map(static fn(QueryTerm $term) => (string)$term, $this->_terms));
    }

    /**
     * @param callable(QueryTerm):bool $test
     */
    private function anyTerm(callable $test): bool
    {
        foreach ($this->_terms as $term) {
            if ($test($term)) {
                return true;
            }
        }

        return false;
    }
}
