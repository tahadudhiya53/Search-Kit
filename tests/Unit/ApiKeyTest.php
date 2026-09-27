<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\models\ApiKey;

/**
 * An API key decides what a request may reach, so what it covers and what it accepts is what
 * stands between the search API and everything it must not answer for.
 */
class ApiKeyTest extends TestCase
{
    public function testAKeyNamingNoIndexCoversEveryIndex(): void
    {
        $key = new ApiKey(['name' => 'Everything']);

        self::assertTrue($key->coversAllIndexes());
        self::assertTrue($key->coversIndex(1));
        self::assertTrue($key->coversIndex(99));
    }

    public function testAKeyNamingIndexesCoversOnlyThose(): void
    {
        $key = new ApiKey(['name' => 'Site search', 'indexIds' => [3, 4]]);

        self::assertFalse($key->coversAllIndexes());
        self::assertTrue($key->coversIndex(3));
        self::assertFalse($key->coversIndex(5));
    }

    public function testRateLimitIsOnlyALimitWhenItIsOne(): void
    {
        self::assertFalse((new ApiKey())->hasRateLimit());
        self::assertFalse((new ApiKey(['rateLimit' => 0]))->hasRateLimit());
        self::assertTrue((new ApiKey(['rateLimit' => 60]))->hasRateLimit());
    }

    public function testAKeyNeedsAName(): void
    {
        $key = new ApiKey();

        self::assertFalse($key->validate());
        self::assertArrayHasKey('name', $key->getErrors());
    }

    public function testRejectsARateLimitNothingCouldBeHeldTo(): void
    {
        $key = new ApiKey(['name' => 'Too much', 'rateLimit' => ApiKey::MAX_RATE_LIMIT + 1]);

        self::assertFalse($key->validate());
        self::assertArrayHasKey('rateLimit', $key->getErrors());

        $zero = new ApiKey(['name' => 'None at all', 'rateLimit' => 0]);

        self::assertFalse($zero->validate());
    }

    public function testRejectsAScopeThatIsNotIndexIds(): void
    {
        $key = new ApiKey(['name' => 'Bad scope', 'indexIds' => [0]]);

        self::assertFalse($key->validate());
        self::assertArrayHasKey('indexIds', $key->getErrors());
    }

    public function testAKeyIsIdentifiedByItsPrefixOnceItIsGone(): void
    {
        $key = new ApiKey(['name' => 'Site search', 'prefix' => 'aB3dEf7h']);

        self::assertSame('aB3dEf7h…', $key->getMaskedKey());
        self::assertStringNotContainsString('…', (string)$key);
    }
}
