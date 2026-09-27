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

    /**
     * Every language the sites a search covers are written in, in the order the sites were given.
     * A search of several sites in one language has one language; one spanning two has both, and
     * nothing may pretend otherwise.
     *
     * @param int[]|null $siteIds The sites the search covers, or null for every site there is.
     * @return string[]
     */
    public function languagesFor(?array $siteIds): array
    {
        // A language set here is the one to read everything in, which is also the only answer
        // available where there is no application to ask about sites.
        if ($this->language !== null) {
            return [$this->language];
        }

        $languages = [];

        // Asked one site at a time, so the language a site is read in is settled in one place.
        foreach ($siteIds ?? Craft::$app->getSites()->getAllSiteIds(true) as $siteId) {
            $language = $this->siteLanguage((int)$siteId);
            $languages[$language] = $language;
        }

        return array_values($languages) ?: [$this->language()];
    }

    /**
     * The one language a search is read in, or null when it covers sites written in more than one.
     *
     * @param int[]|null $siteIds
     */
    public function languageFor(?array $siteIds): ?string
    {
        $languages = $this->languagesFor($siteIds);

        return count($languages) === 1 ? $languages[0] : null;
    }

    /**
     * The language a site's content and queries are reduced in. Craft indexes an element's keywords
     * in its site's language, so anything comparing against that has to ask the same question.
     */
    public function siteLanguage(?int $siteId): string
    {
        // A language set here is the one to read everything in, whatever site is being asked about.
        if ($this->language !== null) {
            return $this->language;
        }

        if ($siteId !== null) {
            $site = Craft::$app->getSites()->getSiteById($siteId, true);

            if ($site !== null) {
                return $site->language;
            }
        }

        return $this->language();
    }

    private function language(): string
    {
        return $this->language ?? Craft::$app->language;
    }
}
