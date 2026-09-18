<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use Tahadudhiya\SearchKit\models\ParsedQuery;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\SearchKit;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\caching\CacheInterface;
use yii\caching\TagDependency;

/**
 * What else someone could search for: completions while they type, a correction for a word the
 * index does not hold, and something to try when a search found nothing. Every suggestion is a word
 * a publicly searchable document still uses, so none of them can lead to another empty result.
 */
class Suggestions extends Component
{
    /**
     * @var int Seconds a lookup is reused for. Words appearing or disappearing invalidate this at
     * once; the expiry is what catches content that becomes published or expires with the clock.
     */
    public const CACHE_DURATION = 300;

    /** @var int Words read at a time while looking for enough that may actually be shown. */
    private const CANDIDATE_BATCH = 30;

    private ?Terms $_terms = null;
    private ?Normalization $_normalization = null;
    private ?CacheInterface $_cache = null;

    /**
     * Completions for what has been typed so far: the whole query, with its last word finished.
     *
     * @return string[]
     */
    public function autocomplete(SearchIndex $index, string $text, int $limit = 5, ?int $siteId = null): array
    {
        $words = $this->getNormalization()->terms($text);

        if ($index->id === null || $words === [] || $limit < 1) {
            return [];
        }

        $prefix = (string)array_pop($words);
        $indexId = (int)$index->id;

        $completions = $this->cached(
            $indexId,
            "autocomplete:$siteId:$prefix:$limit",
            // One more than asked for, since what was typed is not a completion of itself.
            fn() => $this->completionsOf($indexId, $siteId, $prefix, $limit + 1),
        );

        $suggestions = [];

        foreach ($completions as $completion) {
            // What was typed is not a suggestion, however far through a word it happens to be.
            if ($completion !== $prefix) {
                $suggestions[] = implode(' ', [...$words, $completion]);
            }
        }

        return array_slice($suggestions, 0, $limit);
    }

    /**
     * The query with each misspelled word replaced by the closest word the index holds, or null if
     * nothing needed correcting. A phrase, an alternation and an exclusion are all left alone: the
     * first two already say what they accept, and quietly widening what a search rules out would
     * change what it means.
     */
    public function correct(ParsedQuery $parsed, SearchIndex $index, ?int $siteId = null): ?ParsedQuery
    {
        $settings = $index->getSearchSettings();

        if ($index->id === null || !$settings->typoTolerance) {
            return null;
        }

        $corrected = clone $parsed;
        $changed = false;

        foreach ($corrected->getRequiredTerms() as $term) {
            if ($term->phrase || $term->hasAlternatives() || !$settings->allowsCorrection($term->text)) {
                continue;
            }

            $replacement = $this->correction($term->text, $index, $siteId, $settings->typoMaxDistance);

            if ($replacement === null) {
                continue;
            }

            $term->original = $term->text;
            $term->text = $replacement;
            $term->corrected = true;
            $changed = true;
        }

        if (!$changed) {
            return null;
        }

        $corrected->corrected = true;

        return $corrected;
    }

    /**
     * Something to try instead of a query that found nothing: what it was probably meant to say,
     * the words it could be completed to, and the parts of it the index does hold.
     *
     * @return string[]
     */
    public function forQuery(ParsedQuery $parsed, SearchIndex $index, ?int $siteId = null, int $limit = 5): array
    {
        if ($index->id === null || $limit < 1) {
            return [];
        }

        $indexId = (int)$index->id;
        $suggestions = [];
        $correction = $this->correct($parsed, $index, $siteId);

        if ($correction !== null) {
            $suggestions[] = $correction->getText();
        }

        $known = [];
        $required = $parsed->getRequiredTerms();

        foreach ($required as $term) {
            if ($term->phrase) {
                continue;
            }

            if ($this->getTerms()->isSearchable($indexId, $siteId, $term->text)) {
                $known[] = $term->text;
                continue;
            }

            foreach ($this->completionsOf($indexId, $siteId, $term->text, $limit) as $completion) {
                $suggestions[] = $completion;
            }
        }

        // Dropping the words the index has never seen is the last thing worth offering, and only
        // when something is left that is not the query itself.
        if ($known !== [] && count($known) < count($required)) {
            $suggestions[] = implode(' ', $known);
        }

        return array_slice(array_values(array_unique(array_diff($suggestions, [$parsed->getText()]))), 0, $limit);
    }

    /**
     * The closest word a publicly searchable document uses, within the edits allowed. A word the
     * index already holds needs no correction, which is what keeps this off the path of a search
     * that found something.
     */
    private function correction(string $term, SearchIndex $index, ?int $siteId, int $maxDistance): ?string
    {
        $indexId = (int)$index->id;

        return $this->cached($indexId, "correction:$siteId:$term:$maxDistance", function() use ($indexId, $siteId, $term, $maxDistance) {
            if ($this->getTerms()->isSearchable($indexId, $siteId, $term)) {
                return null;
            }

            $byDistance = [];

            // The length band rules out nothing a correction could have been, so every candidate
            // within it is weighed — including one that changes the very first character.
            foreach ($this->getTerms()->withinLengthOf($indexId, $siteId, $term, $maxDistance) as $candidate) {
                $distance = $this->distance($term, $candidate, $maxDistance);

                if ($distance >= 1 && $distance <= $maxDistance) {
                    $byDistance[$distance][] = $candidate;
                }
            }

            ksort($byDistance);

            foreach ($byDistance as $candidates) {
                $closest = $this->firstSearchable($indexId, $siteId, $candidates);

                if ($closest !== null) {
                    return $closest;
                }
            }

            return null;
        });
    }

    /**
     * The first of these words anybody may find. Candidates arrive shortest first and then in
     * alphabetical order, and every one of them is asked about, so a correction buried under any
     * number of hidden words is still found and the same query always corrects the same way.
     *
     * @param string[] $candidates
     */
    private function firstSearchable(int $indexId, ?int $siteId, array $candidates): ?string
    {
        foreach (array_chunk($candidates, self::CANDIDATE_BATCH) as $chunk) {
            $visible = $this->getTerms()->visible($indexId, $siteId, $chunk);

            if ($visible !== []) {
                return $visible[0];
            }
        }

        return null;
    }

    /**
     * Completions anybody may find, read a batch at a time until there are enough of them or the
     * index has no more words starting that way. A run of hidden words delays the answer rather
     * than cutting it short.
     *
     * @return string[]
     */
    private function completionsOf(int $indexId, ?int $siteId, string $prefix, int $limit): array
    {
        $found = [];
        $offset = 0;

        while (count($found) < $limit) {
            $candidates = $this->getTerms()->startingWith($indexId, $siteId, $prefix, self::CANDIDATE_BATCH, $offset);

            if ($candidates === []) {
                break;
            }

            $offset += count($candidates);

            foreach ($this->getTerms()->visible($indexId, $siteId, $candidates) as $completion) {
                $found[] = $completion;
            }

            // A short page is the last one, so there is nothing left to ask for.
            if (count($candidates) < self::CANDIDATE_BATCH) {
                break;
            }
        }

        return array_slice($found, 0, $limit);
    }

    /**
     * How many single-character edits turn one word into the other: an insertion, a deletion, a
     * substitution, or a swap of two neighbouring characters. Counted in characters rather than
     * bytes, so an accented word is not judged further away than it is.
     *
     * @param int|null $limit Stop once the words are further apart than this, which is all the
     *                        caller needs to know and saves working out how much further.
     */
    public function distance(string $from, string $to, ?int $limit = null): int
    {
        $a = $this->characters($from);
        $b = $this->characters($to);
        $rows = count($a);
        $columns = count($b);

        if ($rows === 0 || $columns === 0) {
            return max($rows, $columns);
        }

        $twoBack = [];
        $previous = range(0, $columns);
        $current = [];

        for ($i = 0; $i < $rows; $i++) {
            $current = [$i + 1];
            $best = $current[0];

            for ($j = 0; $j < $columns; $j++) {
                $cost = $a[$i] === $b[$j] ? 0 : 1;
                $distance = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + $cost);

                // Two neighbouring characters the wrong way round is one mistake, not two.
                if ($i > 0 && $j > 0 && $a[$i] === $b[$j - 1] && $a[$i - 1] === $b[$j]) {
                    $distance = min($distance, $twoBack[$j - 1] + $cost);
                }

                $current[$j + 1] = $distance;
                $best = min($best, $distance);
            }

            if ($limit !== null && $best > $limit) {
                return $limit + 1;
            }

            $twoBack = $previous;
            $previous = $current;
        }

        return (int)$current[$columns];
    }

    /**
     * @return string[]
     */
    private function characters(string $text): array
    {
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Suggestions are read far more often than the content behind them changes, and a keystroke is
     * not a reason to ask the database again. A word appearing or disappearing invalidates this at
     * once, so nothing has to wait for the entry to expire.
     *
     * @template T
     * @param callable():T $lookup
     * @return T
     */
    private function cached(int $indexId, string $key, callable $lookup): mixed
    {
        $cache = $this->getCache();
        $cacheKey = 'searchkit:suggestions:' . $indexId . ':' . $key;
        $value = $cache->get($cacheKey);

        if ($value === false) {
            $value = ['value' => $lookup()];

            $cache->set($cacheKey, $value, self::CACHE_DURATION, new TagDependency([
                'tags' => $this->getTerms()->cacheTag($indexId),
            ]));
        }

        return $value['value'];
    }

    public function setCache(CacheInterface $cache): void
    {
        $this->_cache = $cache;
    }

    public function getCache(): CacheInterface
    {
        return $this->_cache ??= Craft::$app->getCache();
    }

    public function setTerms(Terms $terms): void
    {
        $this->_terms = $terms;
    }

    public function getTerms(): Terms
    {
        return $this->_terms ??= $this->plugin()->getTerms();
    }

    public function setNormalization(Normalization $normalization): void
    {
        $this->_normalization = $normalization;
    }

    public function getNormalization(): Normalization
    {
        return $this->_normalization ??= $this->plugin()->getNormalization();
    }

    private function plugin(): SearchKit
    {
        return SearchKit::getInstance()
            ?? throw new InvalidConfigException('SearchKit is not installed or is disabled.');
    }
}
