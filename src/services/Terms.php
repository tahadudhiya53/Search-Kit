<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;
use yii\caching\CacheInterface;
use yii\caching\TagDependency;
use yii\db\Expression;

/**
 * The words each index holds, one row per word per document. Owning them by document is what lets a
 * word disappear once the last document using it stops using it, and what lets a suggestion be
 * checked against content the person searching is actually allowed to find.
 */
class Terms extends Component
{
    /** @var int Longer strings are identifiers rather than words, and nobody completes one. */
    public const MAX_LENGTH = 64;

    /** @var int Rows written per statement while a document's words are recorded. */
    private const INSERT_BATCH = 500;

    /** @var int Documents asked about at a time while proving which words may be shown. */
    private const OWNER_BATCH = 200;

    private ?CacheInterface $_cache = null;

    /**
     * Replaces the words one document contributes. Words other documents also use are left where
     * they are, so a word only disappears when the last document using it stops using it.
     *
     * @param string[] $terms Normalized words, as the pipeline produces them.
     */
    public function record(int $indexId, int $elementId, int $siteId, string $elementType, array $terms): void
    {
        $terms = $this->usable($terms);
        $now = Db::prepareDateForDb(DateTimeHelper::now());
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Rewritten wholesale so a word the document no longer uses goes with the old set.
            Db::delete(Table::TERMS, ['indexId' => $indexId, 'elementId' => $elementId, 'siteId' => $siteId]);

            // A worker that loaded this element before it was deleted must not put its words back.
            // Checked inside the same transaction as the write it guards.
            if (!$this->elementExists($elementId)) {
                $terms = [];
            }

            foreach (array_chunk($terms, self::INSERT_BATCH) as $chunk) {
                Db::batchInsert(
                    Table::TERMS,
                    ['indexId', 'elementId', 'siteId', 'elementType', 'term', 'dateCreated'],
                    array_map(
                        static fn(string $term) => [$indexId, $elementId, $siteId, $elementType, $term, $now],
                        $chunk,
                    ),
                );
            }

            $transaction->commit();
        } catch (Throwable $e) {
            // A half-written set would leave the dictionary describing a document that never was.
            $transaction->rollBack();
            throw $e;
        }

        $this->invalidate($indexId);
    }

    /**
     * Whether the element is still there to be indexed at all. Craft keeps a deleted element's row
     * and stamps it, so this is what tells a live document from one on its way out.
     */
    private function elementExists(int $elementId): bool
    {
        return (new Query())
            ->from([CraftTable::ELEMENTS])
            ->where(['id' => $elementId, 'dateDeleted' => null])
            ->exists();
    }

    /**
     * Forgets everything one document contributed, for one index or for every index covering it.
     */
    public function forgetDocument(int $elementId, int $siteId, ?int $indexId = null): void
    {
        $condition = ['elementId' => $elementId, 'siteId' => $siteId];

        if ($indexId !== null) {
            $condition['indexId'] = $indexId;
        }

        // Read before deleting: once the rows are gone, nothing is left to say which indexes the
        // document was part of, and each of them has its own cache to put right.
        $indexIds = $indexId !== null ? [$indexId] : array_map('intval', (new Query())
            ->select(['indexId'])
            ->distinct()
            ->from([Table::TERMS])
            ->where($condition)
            ->column());

        if ($indexIds === []) {
            return;
        }

        Db::delete(Table::TERMS, $condition);

        foreach ($indexIds as $affected) {
            $this->invalidate($affected);
        }
    }

    /**
     * Forgets everything an index holds, which is how a rebuild starts from the content itself.
     */
    public function clear(int $indexId, ?int $siteId = null): void
    {
        $condition = ['indexId' => $indexId];

        if ($siteId !== null) {
            $condition['siteId'] = $siteId;
        }

        Db::delete(Table::TERMS, $condition);
        $this->invalidate($indexId);
    }

    /**
     * Words starting with the given text, shortest first: the shortest completion is the one that
     * changes what was typed the least. Unfiltered — put the result through {@see self::visible()}.
     *
     * @return string[]
     */
    public function startingWith(int $indexId, ?int $siteId, string $prefix, int $limit, int $offset = 0): array
    {
        if ($prefix === '' || $limit < 1) {
            return [];
        }

        return $this->shortestFirst(
            $this->scoped($indexId, $siteId)->andWhere(['like', 'term', Db::escapeForLike($prefix) . '%', false]),
            $limit,
            $offset,
        );
    }

    /**
     * Every word that could be within the given number of edits of this one. Length is an exact
     * test — no sequence of edits can change a word's length by more than the number of edits — so
     * this narrows the vocabulary without ever ruling out a correction that was possible.
     *
     * Unfiltered, and deliberately unlimited: a cap here would silently change which correction
     * wins. Put the result through {@see self::visible()} once the closest ones are known.
     *
     * @return string[] Shortest first, then alphabetical, so the same word always corrects the same way.
     */
    public function withinLengthOf(int $indexId, ?int $siteId, string $term, int $maxDistance): array
    {
        if ($term === '' || $maxDistance < 1) {
            return [];
        }

        $length = mb_strlen($term);

        return $this->shortestFirst(
            $this->scoped($indexId, $siteId)->andWhere([
                'between',
                new Expression('CHAR_LENGTH([[term]])'),
                max(1, $length - $maxDistance),
                $length + $maxDistance,
            ]),
            null,
        );
    }

    /**
     * Whether this word can be found by someone searching publicly, which is what decides whether
     * it needs correcting at all.
     */
    public function isSearchable(int $indexId, ?int $siteId, string $term): bool
    {
        return $this->visible($indexId, $siteId, [$term]) !== [];
    }

    /**
     * Keeps only the words at least one publicly searchable document still uses, in the order they
     * were given.
     *
     * Suggestions describe content anybody may find: what is published, as Craft itself decides it.
     * Nothing is suggested from a draft, from content that is disabled, not yet posted or expired,
     * or from an element that has since been deleted.
     *
     * Every document using a word is checked until one of them proves the word may be shown, so a
     * word buried under any number of hidden documents is still found. The work is done a batch at
     * a time, and a word stops being asked about the moment it is proven.
     *
     * @param string[] $terms
     * @return string[]
     */
    public function visible(int $indexId, ?int $siteId, array $terms): array
    {
        $terms = array_values(array_unique(array_filter($terms, static fn(string $term) => $term !== '')));

        if ($terms === []) {
            return [];
        }

        $unproven = array_combine($terms, array_fill(0, count($terms), true));
        $proven = [];
        $afterId = 0;

        while ($unproven !== []) {
            $rows = $this->ownerRows($indexId, $siteId, array_keys($unproven), $afterId);

            if ($rows === []) {
                break;
            }

            $afterId = (int)$rows[array_key_last($rows)]['id'];
            $wanted = [];

            foreach ($rows as $row) {
                $wanted[(string)$row['elementType']][(int)$row['siteId']][] = (int)$row['elementId'];
            }

            $searchable = $this->searchableElements($wanted);

            foreach ($rows as $row) {
                $term = (string)$row['term'];

                if (isset($searchable[(string)$row['elementType']][(int)$row['siteId']][(int)$row['elementId']])) {
                    $proven[$term] = true;
                    unset($unproven[$term]);
                }
            }
        }

        return array_values(array_filter($terms, static fn(string $term) => isset($proven[$term])));
    }

    /**
     * The next documents using any of these words. Keyset paging on the row ID means a word being
     * proven, and dropping out of the question, can never make the paging skip a row.
     *
     * @param string[] $terms
     * @return array<array<string,mixed>>
     */
    private function ownerRows(int $indexId, ?int $siteId, array $terms, int $afterId): array
    {
        return (new Query())
            ->select(['id', 'term', 'elementType', 'elementId', 'siteId'])
            ->from([Table::TERMS])
            ->where(['indexId' => $indexId, 'term' => $terms])
            ->andWhere(['>', 'id', $afterId])
            ->andFilterWhere(['siteId' => $siteId])
            ->orderBy(['id' => SORT_ASC])
            ->limit(self::OWNER_BATCH)
            ->all();
    }

    public function countForIndex(int $indexId, ?int $siteId = null): int
    {
        return (int)$this->scoped($indexId, $siteId)->count();
    }

    /**
     * The tag every cached suggestion carries, so a word appearing or disappearing is visible to
     * the next lookup rather than to the one after the cache expires.
     */
    public function cacheTag(int $indexId): string
    {
        return "searchkit:terms:$indexId";
    }

    public function invalidate(int $indexId): void
    {
        TagDependency::invalidate($this->getCache(), $this->cacheTag($indexId));
    }

    /**
     * Which of these elements Craft would return to an unrestricted query — its own definition of
     * published, asked once per element type and site rather than once per word.
     *
     * @param array<string,array<int,int[]>> $wanted Element IDs by element type and site.
     * @return array<string,array<int,array<int,true>>>
     */
    private function searchableElements(array $wanted): array
    {
        $searchable = [];

        foreach ($wanted as $elementType => $bySite) {
            if (!is_subclass_of($elementType, ElementInterface::class)) {
                continue;
            }

            foreach ($bySite as $siteId => $elementIds) {
                try {
                    // No status is set, so this is exactly what an unrestricted search would find.
                    $found = $elementType::find()
                        ->id(array_values(array_unique($elementIds)))
                        ->siteId($siteId)
                        ->ids();
                } catch (Throwable $e) {
                    // A word nobody can be shown is the safe answer, so this is reported, not fatal.
                    Craft::warning(
                        "Could not check which {$elementType} elements a suggestion may name: {$e->getMessage()}",
                        SearchKit::LOG_CATEGORY,
                    );
                    continue;
                }

                foreach ($found as $elementId) {
                    $searchable[$elementType][$siteId][(int)$elementId] = true;
                }
            }
        }

        return $searchable;
    }

    /**
     * A null site means every site the index covers, since an index may cover more than one.
     */
    private function scoped(int $indexId, ?int $siteId): Query
    {
        return (new Query())
            ->select(['term'])
            ->distinct()
            ->from([Table::TERMS])
            ->where(['indexId' => $indexId])
            ->andFilterWhere(['siteId' => $siteId]);
    }

    /**
     * Ordered in the database rather than afterwards, so a limit keeps the shortest words rather
     * than whichever ones came first alphabetically. Length is counted in characters, not bytes.
     *
     * @return string[]
     */
    private function shortestFirst(Query $query, ?int $limit, int $offset = 0): array
    {
        $query->orderBy([new Expression('CHAR_LENGTH([[term]]) ASC'), 'term' => SORT_ASC]);

        if ($limit !== null) {
            $query->limit($limit)->offset($offset);
        }

        return array_map('strval', $query->column());
    }

    /**
     * @param string[] $terms
     * @return string[]
     */
    private function usable(array $terms): array
    {
        $usable = array_filter(
            $terms,
            static fn(string $term) => $term !== '' && mb_strlen($term) <= self::MAX_LENGTH,
        );

        return array_values(array_unique($usable));
    }

    public function setCache(CacheInterface $cache): void
    {
        $this->_cache = $cache;
    }

    public function getCache(): CacheInterface
    {
        return $this->_cache ??= Craft::$app->getCache();
    }
}
