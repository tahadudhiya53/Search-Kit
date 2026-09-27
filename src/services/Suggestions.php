<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use Tahadudhiya\SearchKit\models\ParsedQuery;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\SearchKit;
use yii\base\Component;
use yii\caching\CacheInterface;
use yii\caching\TagDependency;

/**
 * What else someone could search for: completions while they type, a correction for a word the
 * index does not hold, and something to try when a search found nothing. Every suggestion is a word
 * a publicly searchable document still uses, so none of them can lead to another empty result.
 *
 * An index may also offer back what has been searched for before. That is a query somebody typed
 * being shown to the next person, so it is off unless it is asked for, and every word of it still
 * has to be a word publicly searchable content uses — which is what keeps a query nobody else
 * should see out of the box. Nothing about who searched is recorded or read to decide any of it.
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
    private ?Intelligence $_intelligence = null;
    private ?CacheInterface $_cache = null;

    /**
     * Completions for what has been typed so far: the whole query, with its last word finished.
     * Each language the search covers reads what was typed for itself and is answered from the
     * sites written in it, so a completion is always looked for in the form its own site holds.
     *
     * Where the index offers back what has been searched for before, those come first: a whole
     * query people have used and found something with beats a word completed out of the dictionary.
     *
     * @param int|int[]|null $siteId The sites being searched, or null for every site the index covers.
     * @return string[]
     */
    public function autocomplete(SearchIndex $index, string $text, int $limit = 5, int|array|null $siteId = null): array
    {
        if ($index->id === null || $limit < 1 || trim($text) === '') {
            return [];
        }

        $indexId = (int)$index->id;
        $fromHistory = $index->getAnalyticsSettings()->suggestPopularQueries;
        $historical = [];
        $suggestions = [];

        foreach ($this->readings($index, $siteId, $text) as $reading) {
            $words = $reading['words'];

            if ($fromHistory) {
                $historical = [...$historical, ...$this->historical($index, $reading['sites'], implode(' ', $words), $limit)];
            }

            $prefix = (string)array_pop($words);

            $completions = $this->cached(
                $indexId,
                'autocomplete:' . $this->scopeKey($reading['sites']) . ":$prefix:$limit",
                // One more than asked for, since what was typed is not a completion of itself.
                fn() => $this->completionsOf($indexId, $reading['sites'], $prefix, $limit + 1),
            );

            foreach ($completions as $completion) {
                // What was typed is not a suggestion, however far through a word it happens to be.
                if ($completion !== $prefix) {
                    $suggestions[] = implode(' ', [...$words, $completion]);
                }
            }
        }

        return array_slice(array_values(array_unique([...$historical, ...$suggestions])), 0, $limit);
    }

    /**
     * Queries people have searched for that start the way this one does, kept only where every word
     * of them is a word a publicly searchable document still uses. That check is the whole of what
     * makes offering somebody else's wording safe, so it is exhaustive rather than sampled.
     *
     * The reading's own sites are passed through, not just used to check the words: a query is
     * looked for among the searches of these sites, in the language they read it in, so one site's
     * wording is never offered in another simply because its words are visible there too.
     *
     * @param int[]|null $sites The sites this reading answers for.
     * @return string[]
     */
    private function historical(SearchIndex $index, ?array $sites, string $normalized, int $limit): array
    {
        $indexId = (int)$index->id;
        $offered = [];

        // A few more than needed, since one naming content nobody may find drops out below.
        foreach ($this->getIntelligence()->getSuggestableQueries($index, $normalized, $limit * 2, $sites) as $candidate) {
            // What was typed is not a suggestion, however popular it happens to be.
            if ($candidate === $normalized) {
                continue;
            }

            $words = array_values(array_unique($this->getNormalization()->tokenize($candidate)));

            if ($words !== [] && count($this->getTerms()->visible($indexId, $sites, $words)) === count($words)) {
                $offered[] = $candidate;
            }

            if (count($offered) >= $limit) {
                break;
            }
        }

        return $offered;
    }

    /**
     * How the sites a search covers read what was typed, and which of them read it that way. Two
     * languages that reduce the text to the same words are one reading, so a search is only asked
     * more than once where the languages genuinely disagree about the word.
     *
     * @param int|int[]|null $siteId
     * @return array<int,array{words:string[],sites:int[]|null}>
     */
    private function readings(SearchIndex $index, int|array|null $siteId, string $text): array
    {
        $scope = $siteId === null ? null : array_values(array_unique(array_map('intval', (array)$siteId)));
        $languages = $this->getNormalization()->languagesFor($scope ?? ($index->siteId !== null ? [$index->siteId] : null));

        // One language reads for every site in scope, which is every single-site search and every
        // search of sites written in one language.
        if (count($languages) === 1) {
            $words = $this->getNormalization()->terms($text, $languages[0]);

            return $words !== [] ? [['words' => $words, 'sites' => $scope]] : [];
        }

        $readings = [];

        foreach ($scope ?? $this->allSiteIds() as $site) {
            $words = $this->getNormalization()->terms($text, $this->getNormalization()->siteLanguage((int)$site));

            if ($words === []) {
                continue;
            }

            $key = implode(' ', $words);
            $readings[$key]['words'] = $words;
            $readings[$key]['sites'][] = (int)$site;
        }

        return array_values($readings);
    }

    /**
     * @return int[]
     */
    protected function allSiteIds(): array
    {
        return array_map('intval', Craft::$app->getSites()->getAllSiteIds(true));
    }

    /**
     * @param int|int[]|null $siteId
     */
    private function scopeKey(int|array|null $siteId): string
    {
        if ($siteId === null) {
            return '*';
        }

        $sites = array_map('intval', (array)$siteId);
        sort($sites);

        return implode('-', $sites);
    }

    /**
     * The query with each misspelled word replaced by the closest word the index holds, or null if
     * nothing needed correcting. A phrase, an alternation and an exclusion are all left alone: the
     * first two already say what they accept, and quietly widening what a search rules out would
     * change what it means.
     *
     * @param int|int[]|null $siteId The sites being searched, or null for every site the index covers.
     */
    public function correct(ParsedQuery $parsed, SearchIndex $index, int|array|null $siteId = null): ?ParsedQuery
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
     * @param int|int[]|null $siteId The sites being searched, or null for every site the index covers.
     * @return string[]
     */
    public function forQuery(ParsedQuery $parsed, SearchIndex $index, int|array|null $siteId = null, int $limit = 5): array
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

            // A term read in more than one language holds each reading, and the index knowing any
            // one of them is the index knowing the word.
            $held = $this->firstHeld($indexId, $siteId, $term->getTexts());

            if ($held !== null) {
                $known[] = $held;
                continue;
            }

            foreach ($term->getTexts() as $text) {
                foreach ($this->completionsOf($indexId, $siteId, $text, $limit) as $completion) {
                    $suggestions[] = $completion;
                }
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
    private function correction(string $term, SearchIndex $index, int|array|null $siteId, int $maxDistance): ?string
    {
        $indexId = (int)$index->id;
        $key = 'correction:' . $this->scopeKey($siteId) . ":$term:$maxDistance";

        return $this->cached($indexId, $key, function() use ($indexId, $siteId, $term, $maxDistance) {
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
     * The first of these words the index holds in a site anybody may find it in. Candidates arrive
     * shortest first and then alphabetically, and every one is asked about, so the same query
     * always corrects the same way however many hidden words it is buried under.
     *
     * @param int|int[]|null $siteId
     * @param string[] $texts
     */
    private function firstHeld(int $indexId, int|array|null $siteId, array $texts): ?string
    {
        foreach ($texts as $text) {
            if ($this->getTerms()->isSearchable($indexId, $siteId, $text)) {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param int|int[]|null $siteId
     * @param string[] $candidates
     */
    private function firstSearchable(int $indexId, int|array|null $siteId, array $candidates): ?string
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
     * @param int|int[]|null $siteId
     * @return string[]
     */
    private function completionsOf(int $indexId, int|array|null $siteId, string $prefix, int $limit): array
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
        return $this->_terms ??= SearchKit::instance()->getTerms();
    }

    public function setIntelligence(Intelligence $intelligence): void
    {
        $this->_intelligence = $intelligence;
    }

    public function getIntelligence(): Intelligence
    {
        return $this->_intelligence ??= SearchKit::instance()->getIntelligence();
    }

    public function setNormalization(Normalization $normalization): void
    {
        $this->_normalization = $normalization;
    }

    public function getNormalization(): Normalization
    {
        return $this->_normalization ??= SearchKit::instance()->getNormalization();
    }
}
