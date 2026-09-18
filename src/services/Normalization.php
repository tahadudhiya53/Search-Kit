<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\helpers\Search as SearchHelper;
use yii\base\Component;

/**
 * Reduces text to the form the index holds it in. Queries and indexed content both come through
 * here, so what a user types is compared against content on exactly the same terms.
 */
class Normalization extends Component
{
    /**
     * @var string|null The language whose character map is used. The application's language when
     * null, which is the only thing that needs one to be running.
     */
    public ?string $language = null;

    /**
     * Lowercased, stripped of markup, punctuation and diacritics, with whitespace collapsed. This
     * is Craft's own keyword normalization, so SearchKit never disagrees with what Craft indexed.
     */
    public function normalize(string $text, ?string $language = null): string
    {
        if (trim($text) === '') {
            return '';
        }

        return SearchHelper::normalizeKeywords($text, [], true, $language ?? $this->language());
    }

    /**
     * @return string[]
     */
    public function tokenize(string $text): array
    {
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($tokens ?: []));
    }

    /**
     * Normalized text split into its terms — the step a query and a document both go through.
     *
     * @return string[]
     */
    public function terms(string $text, ?string $language = null): array
    {
        return $this->tokenize($this->normalize($text, $language));
    }

    /**
     * One word, normalized. Anything that reduces to more than a word keeps only its first, so a
     * configured term can never quietly become a phrase.
     */
    public function term(string $text, ?string $language = null): string
    {
        return $this->terms($text, $language)[0] ?? '';
    }

    private function language(): string
    {
        return $this->language ?? Craft::$app->language;
    }
}
