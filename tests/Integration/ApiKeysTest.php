<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\db\Query;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\models\ApiKey;
use Tahadudhiya\SearchKit\services\ApiKeys;

/**
 * An API key is the whole of what stands between the search API and anyone at all, so what it
 * stores, what it refuses, and what it holds a caller to are proved against the database itself.
 */
class ApiKeysTest extends IntegrationTestCase
{
    /** @var ApiKey[] */
    private array $createdKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->createdKeys as $key) {
            $this->keys()->deleteKey($key);
        }

        $this->createdKeys = [];

        parent::tearDown();
    }

    public function testAKeyIsReturnedOnceAndStoredOnlyAsADigest(): void
    {
        $key = new ApiKey(['name' => 'Test key']);
        $plainKey = $this->create($key);

        self::assertStringStartsWith(ApiKeys::KEY_PREFIX, $plainKey);
        self::assertStringContainsString($key->prefix, $plainKey);

        $row = $this->row((int)$key->id);

        // Nothing stored is the key, and the digest is not something the key can be read out of.
        self::assertNotContains($plainKey, $row);
        self::assertSame(hash('sha256', $plainKey), $row['hash']);
        self::assertNotSame($plainKey, $row['hash']);
    }

    public function testAValidKeyAuthenticatesAndNothingElseDoes(): void
    {
        $key = new ApiKey(['name' => 'Test key']);
        $plainKey = $this->create($key);

        self::assertSame($key->id, $this->keys()->authenticate($plainKey)?->id);

        foreach ([null, '', '   ', 'nonsense', ApiKeys::KEY_PREFIX, substr($plainKey, 0, -1), $key->prefix] as $presented) {
            self::assertNull($this->keys()->authenticate($presented), "“{$presented}” was accepted.");
        }
    }

    public function testADisabledKeyIsRefusedExactlyAsAnUnknownOneIs(): void
    {
        $key = new ApiKey(['name' => 'Test key']);
        $plainKey = $this->create($key);

        $key->enabled = false;
        self::assertTrue($this->keys()->saveKey($key));

        self::assertNull($this->keys()->authenticate($plainKey));
    }

    public function testRevokingAKeyStopsItWorking(): void
    {
        $key = new ApiKey(['name' => 'Test key']);
        $plainKey = $this->create($key);

        self::assertTrue($this->keys()->deleteKey($key));
        self::assertNull($this->keys()->authenticate($plainKey));
    }

    public function testTheKeyItselfCannotBeChangedBySavingOne(): void
    {
        $key = new ApiKey(['name' => 'Test key']);
        $plainKey = $this->create($key);

        $key->name = 'Renamed';
        $key->hash = hash('sha256', 'something else');
        $key->prefix = 'zzzzzzzz';
        self::assertTrue($this->keys()->saveKey($key));

        $row = $this->row((int)$key->id);

        self::assertSame('Renamed', $row['name']);
        self::assertSame(hash('sha256', $plainKey), $row['hash']);
        self::assertSame($key->id, $this->keys()->authenticate($plainKey)?->id);
    }

    public function testAKeyIsHeldToItsRate(): void
    {
        $key = new ApiKey(['name' => 'Test key', 'rateLimit' => 2]);
        $this->create($key);

        $first = $this->keys()->checkRateLimit($key);
        $second = $this->keys()->checkRateLimit($key);
        $spent = $this->keys()->checkRateLimit($key);

        self::assertNull($first['retryAfter']);
        self::assertNull($second['retryAfter']);
        self::assertSame(2, $spent['limit']);
        self::assertSame(0, $spent['remaining']);
        self::assertNotNull($spent['retryAfter']);
        self::assertGreaterThan(0, $spent['retryAfter']);
    }

    public function testAKeyWithNoRateLimitIsNotHeldToOne(): void
    {
        $key = new ApiKey(['name' => 'Test key']);
        $this->create($key);

        for ($i = 0; $i < 5; $i++) {
            $rate = $this->keys()->checkRateLimit($key);

            self::assertNull($rate['limit']);
            self::assertNull($rate['retryAfter']);
        }
    }

    public function testUseIsRecordedButNotOnEveryRequest(): void
    {
        $key = new ApiKey(['name' => 'Test key']);
        $this->create($key);

        self::assertNull($this->keys()->getKeyById((int)$key->id)?->dateLastUsed);

        $this->keys()->touch($key);
        $firstUse = $this->keys()->getKeyById((int)$key->id)?->dateLastUsed;
        self::assertNotNull($firstUse);

        // A search is a read and stays one: the next request does not write the row again.
        $this->keys()->touch($key);

        self::assertEquals($firstUse, $this->keys()->getKeyById((int)$key->id)?->dateLastUsed);
    }

    public function testAKeyOnlyCoversTheIndexesItWasGiven(): void
    {
        $index = $this->persistIndex($this->newIndex());
        $other = $this->persistIndex($this->newIndex());

        $key = new ApiKey(['name' => 'Test key', 'indexIds' => [(int)$index->id]]);
        $plainKey = $this->create($key);

        $authenticated = $this->keys()->authenticate($plainKey);

        self::assertNotNull($authenticated);
        self::assertTrue($authenticated->coversIndex((int)$index->id));
        self::assertFalse($authenticated->coversIndex((int)$other->id));
    }

    private function create(ApiKey $key): string
    {
        $plainKey = $this->keys()->createKey($key);

        self::assertNotNull($plainKey, implode(' ', $key->getErrorSummary(true)));
        $this->createdKeys[] = $key;

        return $plainKey;
    }

    /**
     * @return array<string,mixed>
     */
    private function row(int $id): array
    {
        $row = (new Query())->from([Table::APIKEYS])->where(['id' => $id])->one();

        self::assertIsArray($row);

        return $row;
    }

    private function keys(): ApiKeys
    {
        return $this->plugin()->getApiKeys();
    }
}
