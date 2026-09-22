<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\db\Query;
use craft\helpers\Json;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\enums\SynonymType;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\Synonym;
use Tahadudhiya\SearchKit\records\SynonymRecord;
use Tahadudhiya\SearchKit\SearchKit;
use yii\base\Component;

/**
 * The words a search treats as the same thing. Every search reads these, so they are cached rather
 * than fetched again for each one.
 */
class Synonyms extends Component
{
    /** @var string Where the whole set is cached, since it is small and read by every search. */
    private const CACHE_KEY = 'searchkit:synonyms';

    /** @var Synonym[]|null */
    private ?array $_synonyms = null;

    private ?Normalization $_normalization = null;

    /**
     * @return Synonym[]
     */
    public function getAllSynonyms(): array
    {
        if ($this->_synonyms !== null) {
            return $this->_synonyms;
        }

        $cache = Craft::$app->getCache();
        $rows = $cache->get(self::CACHE_KEY);

        if (!is_array($rows)) {
            $rows = (new Query())
                ->select(['id', 'indexId', 'siteId', 'type', 'terms', 'replacements', 'enabled', 'sortOrder', 'uid'])
                ->from([Table::SYNONYMS])
                ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
                ->all();

            $cache->set(self::CACHE_KEY, $rows);
        }

        return $this->_synonyms = array_map(fn(array $row) => $this->createSynonymFromRow($row), $rows);
    }

    public function getSynonymById(int $id): ?Synonym
    {
        foreach ($this->getAllSynonyms() as $synonym) {
            if ($synonym->id === $id) {
                return $synonym;
            }
        }

        return null;
    }

    /**
     * The enabled groups governing a search of this index in this site.
     *
     * @return Synonym[]
     */
    public function getSynonymsForIndex(SearchIndex $index, ?int $siteId = null): array
    {
        if ($index->id === null) {
            return [];
        }

        return array_values(array_filter(
            $this->getAllSynonyms(),
            static fn(Synonym $synonym) => $synonym->enabled && $synonym->applies((int)$index->id, $siteId),
        ));
    }

    /**
     * What else a term should be searched for in one site, drawn from every group that covers it.
     *
     * @return string[]
     */
    public function expand(string $term, SearchIndex $index, ?int $siteId = null): array
    {
        $expansions = [];

        foreach ($this->getSynonymsForIndex($index, $siteId) as $synonym) {
            foreach ($synonym->expand($term) as $expansion) {
                $expansions[] = $expansion;
            }
        }

        return array_values(array_unique(array_diff($expansions, [$term])));
    }

    /**
     * What a term may be widened to across every site a search covers. A search is one search, so
     * an expansion may only be applied where it holds in all of them: a group written for one site
     * would otherwise reach the others, and return results a search of those sites alone never had.
     *
     * @param string[] $texts The term, in each form the languages of those sites reduce it to.
     * @param int[]|null $siteIds The sites the search covers, or null for every site the index does.
     * @param string[] $withheld Filled with the expansions that hold in some of those sites but not all.
     * @return string[]
     */
    public function expandAcross(array $texts, SearchIndex $index, ?array $siteIds, array &$withheld = []): array
    {
        $sites = $this->sitesInScope($index, $siteIds);
        $shared = null;
        $seen = [];

        foreach ($sites as $siteId) {
            $expansions = [];

            // Each site reads the term in its own language's form, so a group written there is
            // found whichever way the query folded.
            foreach ($texts as $text) {
                $expansions = [...$expansions, ...$this->expand($text, $index, $siteId)];
            }

            $expansions = array_values(array_unique(array_diff($expansions, $texts)));
            $seen = [...$seen, ...$expansions];
            $shared = $shared === null ? $expansions : array_intersect($shared, $expansions);
        }

        $shared = array_values($shared ?? []);
        $withheld = array_values(array_unique(array_diff($seen, $shared)));

        return $shared;
    }

    /**
     * The concrete sites a search covers: the ones it named, or the ones its index does.
     *
     * @param int[]|null $siteIds
     * @return int[]
     */
    protected function sitesInScope(SearchIndex $index, ?array $siteIds): array
    {
        if ($siteIds !== null && $siteIds !== []) {
            return array_values(array_unique(array_map('intval', $siteIds)));
        }

        if ($index->siteId !== null) {
            return [$index->siteId];
        }

        return array_map('intval', Craft::$app->getSites()->getAllSiteIds(true));
    }

    /**
     * Words are normalized before they are stored, so a search compares like with like rather than
     * failing to match a synonym over a capital letter or an accent.
     */
    public function saveSynonym(Synonym $synonym, bool $runValidation = true): bool
    {
        // Read in the language of the site the synonym is written for, so it is held in the same
        // form a query searching that site reduces to.
        $language = $this->getNormalization()->siteLanguage($synonym->siteId);

        $synonym->terms = $this->normalizeWords($synonym->terms, $language);
        $synonym->replacements = $this->normalizeWords($synonym->replacements, $language);

        if ($runValidation && !$synonym->validate()) {
            return false;
        }

        if (!$this->scopeExists($synonym)) {
            return false;
        }

        $record = $synonym->id !== null
            ? SynonymRecord::findOne($synonym->id)
            : new SynonymRecord();

        if ($record === null) {
            $synonym->addError('id', "No synonym exists with the ID “{$synonym->id}”.");
            return false;
        }

        $record->indexId = $synonym->indexId;
        $record->siteId = $synonym->siteId;
        $record->type = $synonym->type->value;
        $record->terms = Json::encode($synonym->terms);
        $record->replacements = $synonym->replacements !== [] ? Json::encode($synonym->replacements) : null;
        $record->enabled = $synonym->enabled;
        $record->sortOrder = $synonym->sortOrder;

        if (!$record->save()) {
            $synonym->addErrors($record->getErrors());
            return false;
        }

        $synonym->id = $record->id;
        $synonym->uid = $record->uid;
        $this->invalidate();

        return true;
    }

    public function deleteSynonym(Synonym $synonym): bool
    {
        if ($synonym->id === null) {
            return false;
        }

        $record = SynonymRecord::findOne($synonym->id);

        if ($record === null) {
            return false;
        }

        $deleted = (bool)$record->delete();
        $this->invalidate();

        return $deleted;
    }

    /**
     * Forgets the cached set, so the next search reads what was just saved.
     */
    public function invalidate(): void
    {
        $this->_synonyms = null;
        Craft::$app->getCache()->delete(self::CACHE_KEY);
    }

    /**
     * Caught here rather than left to a foreign key, so callers get a validation error.
     */
    private function scopeExists(Synonym $synonym): bool
    {
        if ($synonym->indexId !== null && $this->getIndexes()->getIndexById($synonym->indexId) === null) {
            $synonym->addError('indexId', "No search index exists with the ID “{$synonym->indexId}”.");
            return false;
        }

        if ($synonym->siteId !== null && Craft::$app->getSites()->getSiteById($synonym->siteId) === null) {
            $synonym->addError('siteId', "No site exists with the ID “{$synonym->siteId}”.");
            return false;
        }

        return true;
    }

    /**
     * @param string[] $words
     * @return string[]
     */
    private function normalizeWords(array $words, ?string $language = null): array
    {
        $normalized = [];

        foreach ($words as $word) {
            // A multi-word entry stays as it is: a synonym may be a phrase, and the pipeline
            // matches one against the query the same way it matches a single word.
            $value = $this->getNormalization()->normalize($word, $language);

            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function createSynonymFromRow(array $row): Synonym
    {
        return new Synonym([
            'id' => (int)$row['id'],
            'indexId' => $row['indexId'] !== null ? (int)$row['indexId'] : null,
            'siteId' => $row['siteId'] !== null ? (int)$row['siteId'] : null,
            'type' => SynonymType::tryFrom((string)$row['type']) ?? SynonymType::TwoWay,
            'terms' => (array)Json::decodeIfJson($row['terms']),
            'replacements' => $row['replacements'] !== null ? (array)Json::decodeIfJson($row['replacements']) : [],
            'enabled' => (bool)$row['enabled'],
            'sortOrder' => (int)$row['sortOrder'],
            'uid' => (string)$row['uid'],
        ]);
    }

    public function setNormalization(Normalization $normalization): void
    {
        $this->_normalization = $normalization;
    }

    public function getNormalization(): Normalization
    {
        return $this->_normalization ??= SearchKit::instance()->getNormalization();
    }

    private function getIndexes(): Indexes
    {
        return SearchKit::instance()->getIndexes();
    }
}
