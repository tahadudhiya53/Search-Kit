<?php

namespace Tahadudhiya\SearchKit\services;

use Tahadudhiya\SearchKit\models\SearchSettings;
use Tahadudhiya\SearchKit\SearchKit;
use yii\base\Component;
use yii\base\InvalidConfigException;

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
     * @return string[]
     */
    public function getWords(SearchSettings $settings): array
    {
        if (!$settings->stopWords) {
            return [];
        }

        $custom = array_map(
            fn(string $word) => $this->getNormalization()->term($word),
            $settings->customStopWords,
        );

        return array_values(array_unique([...self::DEFAULT_WORDS, ...array_filter($custom)]));
    }

    public function isStopWord(string $term, SearchSettings $settings): bool
    {
        return in_array($term, $this->getWords($settings), true);
    }

    /**
     * Removes the stop words from a set of terms, and reports what went. A query that is nothing
     * but stop words is left alone: searching for “the and of” should not search for nothing.
     *
     * @param string[] $terms
     * @param string[] $removed Filled with the terms that were dropped.
     * @return string[]
     */
    public function filter(array $terms, SearchSettings $settings, array &$removed = []): array
    {
        $words = $this->getWords($settings);

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
        return $this->_normalization ??= SearchKit::getInstance()?->getNormalization()
            ?? throw new InvalidConfigException('SearchKit is not installed or is disabled.');
    }
}
