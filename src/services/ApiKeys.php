<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\models\ApiKey;
use Tahadudhiya\SearchKit\records\ApiKeyRecord;
use yii\base\Component;

/**
 * The keys the search API is reached with: creating them, checking one against a request, and
 * holding each one to the rate it was given.
 */
class ApiKeys extends Component
{
    /** @var string What every key starts with, so one can be recognized wherever it turns up. */
    public const KEY_PREFIX = 'sk_';

    /** @var int Requests a minute a new key is given unless something else is asked for. */
    public const DEFAULT_RATE_LIMIT = 60;

    /** @var int Characters of randomness in a key. */
    private const SECRET_LENGTH = 40;

    /** @var int The window a rate limit is expressed over. */
    private const RATE_WINDOW = 60;

    /** @var int How often a key's last use is written back, so searching is not a write per request. */
    private const LAST_USED_INTERVAL = 60;

    /**
     * @return ApiKey[]
     */
    public function getAllKeys(): array
    {
        return array_map(
            fn(array $row) => $this->createKeyFromRow($row),
            $this->createQuery()->orderBy(['name' => SORT_ASC, 'id' => SORT_ASC])->all(),
        );
    }

    public function getKeyById(int $id): ?ApiKey
    {
        $row = $this->createQuery()->where(['id' => $id])->one();

        return $row !== null ? $this->createKeyFromRow($row) : null;
    }

    /**
     * Creates a key and returns it, the once. Nothing keeps the key itself, so this is the only
     * moment it exists anywhere — a lost key is replaced rather than recovered.
     */
    public function createKey(ApiKey $key, bool $runValidation = true): ?string
    {
        $secret = Craft::$app->getSecurity()->generateRandomString(self::SECRET_LENGTH);
        $plainKey = self::KEY_PREFIX . $secret;

        $key->id = null;
        $key->hash = $this->hash($plainKey);
        $key->prefix = substr($secret, 0, ApiKey::PREFIX_LENGTH);

        return $this->saveKey($key, $runValidation) ? $plainKey : null;
    }

    /**
     * Saves everything about a key except the key itself, which can never be changed or recovered.
     */
    public function saveKey(ApiKey $key, bool $runValidation = true): bool
    {
        if ($runValidation && !$key->validate()) {
            return false;
        }

        $record = $key->id !== null ? ApiKeyRecord::findOne($key->id) : new ApiKeyRecord();

        if ($record === null) {
            $key->addError('id', "No API key exists with the ID “{$key->id}”.");
            return false;
        }

        $record->name = $key->name;
        $record->enabled = $key->enabled;
        $record->indexIds = Json::encode(array_values($key->indexIds));
        $record->rateLimit = $key->hasRateLimit() ? $key->rateLimit : null;

        // The digest and the prefix are only ever written when the key itself is created.
        if ($record->getIsNewRecord()) {
            $record->hash = $key->hash;
            $record->prefix = $key->prefix;
        }

        if (!$record->save()) {
            $key->addErrors($record->getErrors());
            return false;
        }

        $key->id = (int)$record->id;
        $key->uid = (string)$record->uid;

        return true;
    }

    public function deleteKey(ApiKey $key): bool
    {
        if ($key->id === null) {
            return false;
        }

        ApiKeyRecord::findOne($key->id)?->delete();

        return true;
    }

    /**
     * The key a request presented, or null when it presented nothing usable. A disabled key is no
     * more valid than an unknown one, which is what makes disabling it a revocation.
     */
    public function authenticate(?string $presented): ?ApiKey
    {
        $presented = trim((string)$presented);

        if ($presented === '') {
            return null;
        }

        $row = $this->createQuery()->where(['hash' => $this->hash($presented)])->one();

        if ($row === null) {
            return null;
        }

        $key = $this->createKeyFromRow($row);

        return $key->enabled ? $key : null;
    }

    /**
     * What this key has left of its rate. `retryAfter` is the seconds until the next request would
     * be allowed, and is only ever set when this one must be refused.
     *
     * @return array{limit:int|null,remaining:int|null,retryAfter:int|null}
     */
    public function checkRateLimit(ApiKey $key): array
    {
        if (!$key->hasRateLimit() || $key->id === null) {
            return ['limit' => null, 'remaining' => null, 'retryAfter' => null];
        }

        $limit = (int)$key->rateLimit;
        $cache = Craft::$app->getCache();
        $cacheKey = "searchkit:ratelimit:{$key->id}";
        $now = microtime(true);

        // A leaking bucket rather than a fixed window, so a key cannot spend two windows' worth of
        // requests either side of a boundary.
        $stored = $cache->get($cacheKey);
        [$allowance, $updatedAt] = is_array($stored) && count($stored) === 2 ? $stored : [(float)$limit, $now];

        $allowance = min((float)$limit, (float)$allowance + ($now - (float)$updatedAt) * $limit / self::RATE_WINDOW);

        if ($allowance < 1) {
            $cache->set($cacheKey, [$allowance, $now], self::RATE_WINDOW * 2);

            return [
                'limit' => $limit,
                'remaining' => 0,
                'retryAfter' => max(1, (int)ceil((1 - $allowance) * self::RATE_WINDOW / $limit)),
            ];
        }

        $allowance--;
        $cache->set($cacheKey, [$allowance, $now], self::RATE_WINDOW * 2);

        return ['limit' => $limit, 'remaining' => (int)floor($allowance), 'retryAfter' => null];
    }

    /**
     * Records that a key was used, at most once a minute: a search is a read, and it stays one.
     */
    public function touch(ApiKey $key): void
    {
        $now = DateTimeHelper::now();

        if ($key->id === null
            || ($key->dateLastUsed !== null && $now->getTimestamp() - $key->dateLastUsed->getTimestamp() < self::LAST_USED_INTERVAL)
        ) {
            return;
        }

        Craft::$app->getDb()->createCommand()
            ->update(Table::APIKEYS, ['dateLastUsed' => Db::prepareDateForDb($now)], ['id' => $key->id])
            ->execute();

        $key->dateLastUsed = $now;
    }

    /**
     * A key is high-entropy random, so an unkeyed digest is enough: it cannot be reversed, and
     * there is nothing to guess at. Nothing else ever hashes a key.
     */
    private function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    private function createQuery(): Query
    {
        return (new Query())
            ->select(['id', 'name', 'hash', 'prefix', 'enabled', 'indexIds', 'rateLimit', 'dateLastUsed', 'dateCreated', 'dateUpdated', 'uid'])
            ->from([Table::APIKEYS]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function createKeyFromRow(array $row): ApiKey
    {
        $indexIds = $row['indexIds'] !== null ? Json::decodeIfJson((string)$row['indexIds']) : [];

        return new ApiKey([
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'hash' => (string)$row['hash'],
            'prefix' => (string)$row['prefix'],
            'enabled' => (bool)$row['enabled'],
            'indexIds' => is_array($indexIds) ? array_values(array_map('intval', $indexIds)) : [],
            'rateLimit' => $row['rateLimit'] !== null ? (int)$row['rateLimit'] : null,
            'dateLastUsed' => DateTimeHelper::toDateTime($row['dateLastUsed']) ?: null,
            'dateCreated' => DateTimeHelper::toDateTime($row['dateCreated']) ?: null,
            'dateUpdated' => DateTimeHelper::toDateTime($row['dateUpdated']) ?: null,
            'uid' => (string)$row['uid'],
        ]);
    }
}
