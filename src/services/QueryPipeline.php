<?php

namespace Tahadudhiya\SearchKit\services;

use Tahadudhiya\SearchKit\base\SearchProviderInterface;
use Tahadudhiya\SearchKit\enums\PartialMatchMode;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\models\ParsedQuery;
use Tahadudhiya\SearchKit\models\QueryTerm;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchSettings;
use Tahadudhiya\SearchKit\SearchKit;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Turns what a user typed into terms a provider can run: operators, normalization, tokenization,
 * stop words and synonyms, in that order and each in one place.
 */
class QueryPipeline extends Component
{
    /** @var string The word that makes two terms interchangeable, as Craft's own syntax spells it. */
    private const ALTERNATION = 'OR';

    /** @var string Operators, then everything that is not whitespace. Phrases use double quotes. */
    private const SYNTAX = '/(-?)(?:"([^"]*)"|(\S+))/u';

    /**
     * @var int How many words one term may be widened to by synonyms. A group large enough to pass
     * this is a configuration mistake, and running it would put that mistake into every query.
     */
    private const MAX_SYNONYMS = 20;

    private ?Normalization $_normalization = null;
    private ?StopWords $_stopWords = null;
    private ?Synonyms $_synonyms = null;

    /**
     * @throws UnsupportedCapabilityException if the query asks for something the provider cannot do.
     * @throws InvalidQueryException if the operators say two things that cannot both be true.
     */
    public function parse(SearchQuery $query, SearchIndex $index, ?SearchProviderInterface $provider = null): ParsedQuery
    {
        $settings = $index->getSearchSettings();

        $parsed = new ParsedQuery([
            'raw' => $query->text,
            'normalized' => $this->getNormalization()->normalize($query->text),
        ]);

        $parsed->setTerms($settings->operators
            ? $this->readSyntax($query->text)
            : $this->readWords($parsed->normalized));

        $this->assertProviderCanRun($parsed, $provider);
        $this->dropStopWords($parsed, $settings);
        $this->applyPartialMatching($parsed, $settings, $provider);
        $this->applySynonyms($parsed, $index, $settings, $query->siteId, $provider);

        return $parsed;
    }

    /**
     * Reads the operators out of what was typed, then normalizes each term. Normalization runs
     * afterwards because it removes the punctuation the operators are written with.
     *
     * @return QueryTerm[]
     * @throws InvalidQueryException if the operators say two things that cannot both be true.
     */
    private function readSyntax(string $text): array
    {
        preg_match_all(self::SYNTAX, $text, $matches, PREG_SET_ORDER);

        $terms = [];
        $alternation = false;

        foreach ($matches as $match) {
            $quoted = ($match[2] ?? '') !== '' || ($match[3] ?? '') === '';
            $token = $quoted ? ($match[2] ?? '') : $match[3];

            if (!$quoted && $token === self::ALTERNATION) {
                // Only meaningful between two terms; one with nothing on either side is dropped.
                $alternation = $terms !== [];
                continue;
            }

            $term = $this->createTerm($token, $quoted, $match[1] === '-');

            if ($term === null) {
                continue;
            }

            if ($alternation) {
                $this->joinAlternative($terms[array_key_last($terms)], $term);
                $alternation = false;
                continue;
            }

            $terms[] = $term;
        }

        return $terms;
    }

    /**
     * One plain term per word, for an index that does not read operators.
     *
     * @return QueryTerm[]
     */
    private function readWords(string $normalized): array
    {
        return array_map(
            static fn(string $word) => QueryTerm::make($word),
            $this->getNormalization()->tokenize($normalized),
        );
    }

    /**
     * A term either rules results in or rules them out, and “or” between those two says nothing
     * anyone could act on. Refusing it is the only honest answer: either reading silently searches
     * for something other than what was written.
     *
     * @throws InvalidQueryException
     */
    private function joinAlternative(QueryTerm $term, QueryTerm $alternative): void
    {
        if ($term->excluded || $alternative->excluded) {
            throw new InvalidQueryException(
                'An excluded term cannot be one of two alternatives.',
                ['text' => ['“OR” joins terms to look for. Write an exclusion as its own term instead.']],
            );
        }

        $term->addAlternative($alternative);
    }

    /**
     * @param bool $quoted Whether the term was written as a phrase.
     */
    private function createTerm(string $token, bool $quoted, bool $excluded): ?QueryTerm
    {
        $partial = $quoted ? PartialMatchMode::Off : $this->readWildcards($token);
        $text = $this->getNormalization()->normalize($token);

        if ($text === '') {
            return null;
        }

        $term = QueryTerm::make($text, $token);
        $term->excluded = $excluded;
        $term->partial = $partial;
        $term->wildcard = $partial !== PartialMatchMode::Off;

        // A term of several words has to be matched as one, whether it was quoted or became more
        // than one word through normalization. A single word never needs that, quoted or not.
        $term->phrase = str_contains($text, ' ');

        // Quoting a single word is how you ask for that word and nothing longer.
        $term->exact = $quoted && !$term->phrase;

        return $term;
    }

    /**
     * Reads `term*` and `*term*` off a token, and strips them so normalization sees the word alone.
     */
    private function readWildcards(string &$token): PartialMatchMode
    {
        $left = str_starts_with($token, '*');
        $right = str_ends_with($token, '*');

        if (!$left && !$right) {
            return PartialMatchMode::Off;
        }

        $token = trim($token, '*');

        // A leading wildcard alone would ask for the end of a word, which few engines can answer,
        // so it is read as the wider request it resembles.
        return $left ? PartialMatchMode::Substring : PartialMatchMode::Prefix;
    }

    private function dropStopWords(ParsedQuery $parsed, SearchSettings $settings): void
    {
        $candidates = [];

        foreach ($parsed->getTerms() as $term) {
            if (!$term->excluded && !$term->phrase && !$term->hasAlternatives()) {
                $candidates[] = $term->text;
            }
        }

        if ($candidates === []) {
            return;
        }

        $removed = [];
        $this->getStopWords()->filter($candidates, $settings, $removed);

        if ($removed === []) {
            return;
        }

        $parsed->removedStopWords = $removed;
        $parsed->setTerms(array_filter(
            $parsed->getTerms(),
            static fn(QueryTerm $term) => $term->excluded || $term->phrase || !in_array($term->text, $removed, true),
        ));
    }

    /**
     * The index's own matching mode, for terms no operator already decided. Exclusions are left
     * whole: ruling results out on part of a word is rarely what anyone means.
     */
    private function applyPartialMatching(
        ParsedQuery $parsed,
        SearchSettings $settings,
        ?SearchProviderInterface $provider,
    ): void {
        if (!$this->supports($provider, ProviderCapability::PartialMatching)) {
            return;
        }

        foreach ($parsed->getTerms() as $term) {
            if (!$term->wildcard && !$term->excluded && !$term->phrase
                && !$term->exact && $settings->allowsPartialMatch($term->text)) {
                $term->partial = $settings->partialMatching;
            }
        }
    }

    /**
     * Configured equivalents for each term. This is configuration rather than something the query
     * asked for, so an index served by a provider that cannot offer alternatives simply goes
     * without them instead of being refused.
     */
    private function applySynonyms(
        ParsedQuery $parsed,
        SearchIndex $index,
        SearchSettings $settings,
        ?int $siteId,
        ?SearchProviderInterface $provider,
    ): void {
        if (!$settings->synonyms || !$this->supports($provider, ProviderCapability::TermAlternation)) {
            return;
        }

        foreach ($parsed->getRequiredTerms() as $term) {
            $expansions = $this->getSynonyms()->expand($term->text, $index, $siteId);

            $term->addSynonyms(...array_slice($expansions, 0, self::MAX_SYNONYMS));
        }
    }

    /**
     * What the query itself asked for has to be honoured or refused: quietly running a different
     * search from the one that was written is worse than saying it cannot be run.
     *
     * @throws UnsupportedCapabilityException
     */
    private function assertProviderCanRun(ParsedQuery $parsed, ?SearchProviderInterface $provider): void
    {
        if ($provider === null) {
            return;
        }

        $required = [
            [ProviderCapability::PhraseMatching, $parsed->hasPhrases()],
            [ProviderCapability::TermExclusion, $parsed->hasExclusions()],
            [ProviderCapability::TermAlternation, $parsed->hasAlternatives()],
            [ProviderCapability::PartialMatching, $parsed->hasPartialMatching()],
        ];

        foreach ($required as [$capability, $asked]) {
            if ($asked && !$provider->supports($capability)) {
                throw UnsupportedCapabilityException::for($provider::displayName(), $capability);
            }
        }
    }

    private function supports(?SearchProviderInterface $provider, ProviderCapability $capability): bool
    {
        return $provider === null || $provider->supports($capability);
    }

    public function setNormalization(Normalization $normalization): void
    {
        $this->_normalization = $normalization;
    }

    public function getNormalization(): Normalization
    {
        return $this->_normalization ??= $this->plugin()->getNormalization();
    }

    public function setStopWords(StopWords $stopWords): void
    {
        $this->_stopWords = $stopWords;
    }

    public function getStopWords(): StopWords
    {
        return $this->_stopWords ??= $this->plugin()->getStopWords();
    }

    public function setSynonyms(Synonyms $synonyms): void
    {
        $this->_synonyms = $synonyms;
    }

    public function getSynonyms(): Synonyms
    {
        return $this->_synonyms ??= $this->plugin()->getSynonyms();
    }

    private function plugin(): SearchKit
    {
        return SearchKit::getInstance()
            ?? throw new InvalidConfigException('SearchKit is not installed or is disabled.');
    }
}
