<?php

namespace Tahadudhiya\SearchKit\services;

use Tahadudhiya\SearchKit\models\SearchSettings;
use Tahadudhiya\SearchKit\SearchKit;
use yii\base\Component;

/**
 * Drops words too common to narrow a search down. The list is deliberately short: a word that only
 * looks common is better handled by relevance than by refusing to search for it.
 */
class StopWords extends Component
{
    /** @var string[] English function words, in the normalized form a query arrives in. */
    public const DEFAULT_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'for', 'from', 'has', 'have', 'he',
        'her', 'his', 'i', 'in', 'into', 'is', 'it', 'its', 'of', 'on', 'or', 'our', 's', 'she',
        'that', 'the', 'their', 'them', 'there', 'these', 'they', 'this', 'to', 'was', 'were',
        'will', 'with', 'you', 'your',
    ];

    private ?Normalization $_normalization = null;

    /**
     * The words to drop. The built-in list is English, so it is only used where every language the
     * search covers is English — dropping “was” from a German query would be removing a word that
     * narrows it, and a search spanning English and German has to be safe for both.
     *
     * A configured word is dropped whatever the language, and is reduced in each of them, so it is
     * recognised in whichever form the query arrived in.
     *
     * @param string|string[]|null $language The languages the search is read in.
     * @return string[]
     */
    public function getWords(SearchSettings $settings, string|array|null $language = null): array
    {
        if (!$settings->stopWords) {
            return [];
        }

        $languages = $language === null ? [null] : (array)$language;
        $custom = [];

        foreach ($languages as $one) {
            foreach ($settings->customStopWords as $word) {
                $custom[] = $this->getNormalization()->term($word, $one);
            }
        }

        $built = self::isEnglish($languages) ? self::DEFAULT_WORDS : [];

        return array_values(array_unique([...$built, ...array_filter($custom)]));
    }

    public function isStopWord(string $term, SearchSettings $settings, string|array|null $language = null): bool
    {
        return in_array($term, $this->getWords($settings, $language), true);
    }

    /**
     * Whether the built-in list is written for every language the search covers. A null language is
     * the application's, which the list has always been read as.
     *
     * @param array<int,string|null> $languages
     */
    private static function isEnglish(array $languages): bool
    {
        foreach ($languages as $language) {
            if ($language !== null && !str_starts_with(strtolower($language), 'en')) {
                return false;
            }
        }

        return $languages !== [];
    }

    /**
     * Removes the stop words from a set of terms, and reports what went. A query that is nothing
     * but stop words is left alone: searching for “the and of” should not search for nothing.
     *
     * @param string[] $terms
     * @param string[] $removed Filled with the terms that were dropped.
     * @return string[]
     */
    public function filter(
        array $terms,
        SearchSettings $settings,
        array &$removed = [],
        string|array|null $language = null,
    ): array {
        $words = $this->getWords($settings, $language);

        if ($words === []) {
            return $terms;
        }

        $kept = array_values(array_filter($terms, static fn(string $term) => !in_array($term, $words, true)));

        if ($kept === []) {
            return $terms;
        }

        $removed = array_values(array_diff($terms, $kept));

        return $kept;
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
