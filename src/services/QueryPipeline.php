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
        $scope = $query->getSiteScope($index->siteId);

        // Craft reduces an element's keywords in its site's language, so a query is read in the
        // language of every site it is searching — one of them, or each of several.
        $languages = $this->getNormalization()->languagesFor($scope);

        $parsed = new ParsedQuery([
            'raw' => $query->text,
            'languages' => $languages,
            'normalized' => $this->getNormalization()->normalize($query->text, $languages[0]),
        ]);

        $parsed->setTerms($settings->operators
            ? $this->readSyntax($query->text, $languages)
            : $this->readWords($query->text, $languages));

        $this->assertProviderCanRun($parsed, $provider);
        $this->dropStopWords($parsed, $settings, $languages);
        $this->applyPartialMatching($parsed, $settings, $provider);
        $this->applySynonyms($parsed, $index, $settings, $scope, $provider);

        return $parsed;
    }

    /**
     * Reads the operators out of what was typed, then normalizes each term. Normalization runs
     * afterwards because it removes the punctuation the operators are written with.
     *
     * @return QueryTerm[]
     * @throws InvalidQueryException if the operators say two things that cannot both be true.
     */
    private function readSyntax(string $text, array $languages): array
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

            $term = $this->createTerm($token, $quoted, $match[1] === '-', $languages);

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
     * One plain term per word, for an index that does not read operators. Each language reads the
     * text for itself, and every reading has to make the same number of words of it — otherwise
     * there is no term to pair up, and running one language's reading in place of another's would
     * search a site for words nobody asked it for.
     *
     * @param string[] $languages
     * @return QueryTerm[]
     * @throws InvalidQueryException if the languages do not read the text as the same words.
     */
    private function readWords(string $text, array $languages): array
    {
        $readings = [];

        foreach ($languages as $language) {
            $readings[$language] = $this->getNormalization()->terms($text, $language);
        }

        $primary = $readings[$languages[0]];

        $disagreeing = array_keys(array_filter(
            $readings,
            static fn(array $reading) => count($reading) !== count($primary),
        ));

        if ($disagreeing !== []) {
            throw $this->unreadable([$languages[0], ...$disagreeing]);
        }

        $terms = [];

        foreach ($primary as $position => $word) {
            $term = QueryTerm::make($word);

            foreach ($languages as $language) {
                $this->addLanguageVariant($term, $readings[$language][$position]);
            }

            $terms[] = $term;
        }

        return $terms;
    }

    /**
     * A search whose languages disagree about what was even typed. Refused rather than answered:
     * either reading searches one of the sites for something other than what was written, and which
     * one is not something to decide on a site's behalf.
     *
     * @param string[] $languages
     */
    private function unreadable(array $languages): InvalidQueryException
    {
        $named = implode(' and ', array_map(
            static fn(string $language) => "“{$language}”",
            array_values(array_unique($languages)),
        ));

        return new InvalidQueryException(
            'This search covers sites whose languages read the query differently.',
            ['text' => [
                "{$named} do not read this query as the same words, and neither may stand in for "
                . 'the other. Search the sites of one language at a time.',
            ]],
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
     * @param string[] $languages Every language the term is read in, the first one deciding its text.
     */
    private function createTerm(string $token, bool $quoted, bool $excluded, array $languages): ?QueryTerm
    {
        $partial = $quoted ? PartialMatchMode::Off : $this->readWildcards($token);
        $readings = [];

        foreach ($languages as $language) {
            $readings[$language] = $this->getNormalization()->normalize($token, $language);
        }

        // Nothing in any of them: punctuation, which was never a term in the first place.
        if (implode('', $readings) === '') {
            return null;
        }

        // A word to one language and nothing at all to another is the same disagreement a differing
        // number of words is, and it is refused the same way.
        $silent = array_keys(array_filter($readings, static fn(string $reading) => $reading === ''));

        if ($silent !== []) {
            throw $this->unreadable([$languages[0], ...$silent]);
        }

        $text = $readings[$languages[0]];
        $term = QueryTerm::make($text, $token);
        $term->excluded = $excluded;
        $term->partial = $partial;
        $term->wildcard = $partial !== PartialMatchMode::Off;

        // A term of several words has to be matched as one, whether it was quoted or became more
        // than one word through normalization. A single word never needs that, quoted or not.
        $term->phrase = str_contains($text, ' ');

        // Quoting a single word is how you ask for that word and nothing longer.
        $term->exact = $quoted && !$term->phrase;

        // A word folds differently from one language to the next, and each site's content was
        // indexed in its own. Every reading is accepted, so neither site is searched for the other's
        // spelling of the word — and a term the languages agree on stays one term.
        foreach ($readings as $reading) {
            $this->addLanguageVariant($term, $reading);
        }

        return $term;
    }

    /**
     * Another language's reading of the same word, accepted alongside it. A reading the term
     * already has adds nothing, which is what keeps a single-language search to one term.
     */
    private function addLanguageVariant(QueryTerm $term, string $text): void
    {
        if ($text === '' || in_array($text, $term->getTexts(), true)) {
            return;
        }

        $variant = QueryTerm::make($text);
        $variant->partial = $term->partial;
        $variant->wildcard = $term->wildcard;
        $variant->exact = $term->exact;
        $variant->phrase = str_contains($text, ' ');

        $term->addAlternative($variant);
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

    /**
     * @param string[] $languages
     */
    private function dropStopWords(ParsedQuery $parsed, SearchSettings $settings, array $languages): void
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
        $this->getStopWords()->filter($candidates, $settings, $removed, $languages);

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
        ?array $scope,
        ?SearchProviderInterface $provider,
    ): void {
        if (!$settings->synonyms || !$this->supports($provider, ProviderCapability::TermAlternation)) {
            return;
        }

        $withheld = [];

        foreach ($parsed->getRequiredTerms() as $term) {
            $dropped = [];

            // Only what holds in every site being searched: a group written for one site would
            // otherwise return results there that a search of the others never had.
            $expansions = $this->getSynonyms()->expandAcross($term->getTexts(), $index, $scope, $dropped);

            $term->addSynonyms(...array_slice($expansions, 0, self::MAX_SYNONYMS));
            $withheld = [...$withheld, ...$dropped];
        }

        $parsed->withheldSynonyms = array_values(array_unique($withheld));
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
        return $this->_normalization ??= SearchKit::instance()->getNormalization();
    }

    public function setStopWords(StopWords $stopWords): void
    {
        $this->_stopWords = $stopWords;
    }

    public function getStopWords(): StopWords
    {
        return $this->_stopWords ??= SearchKit::instance()->getStopWords();
    }

    public function setSynonyms(Synonyms $synonyms): void
    {
        $this->_synonyms = $synonyms;
    }

    public function getSynonyms(): Synonyms
    {
        return $this->_synonyms ??= SearchKit::instance()->getSynonyms();
    }
}
