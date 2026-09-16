<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use craft\base\ElementInterface;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\Tests\Support\MinimalProvider;

class SearchProviderTest extends TestCase
{
    public function testSupportsReflectsDeclaredCapabilities(): void
    {
        $provider = new MinimalProvider();

        self::assertTrue($provider->supports(ProviderCapability::Search));
        self::assertTrue($provider->supports(ProviderCapability::Indexing));
        self::assertFalse($provider->supports(ProviderCapability::Rebuilding));
    }

    public function testUndeclaredOperationsFailLoudly(): void
    {
        $provider = new MinimalProvider();
        $index = new SearchIndex(['handle' => 'siteSearch']);

        $this->expectException(UnsupportedCapabilityException::class);
        $provider->rebuild($index);
    }

    public function testDeletingIsRejectedWhenNotDeclared(): void
    {
        $provider = new MinimalProvider();

        $this->expectException(UnsupportedCapabilityException::class);
        $provider->deleteElement(new SearchIndex(['handle' => 'siteSearch']), $this->createMock(ElementInterface::class));
    }

    public function testCraftProviderDoesNotClaimCapabilitiesCraftLacks(): void
    {
        $provider = new CraftProvider();

        self::assertTrue($provider->supports(ProviderCapability::Search));
        self::assertFalse($provider->supports(ProviderCapability::FieldWeighting));
        self::assertFalse($provider->supports(ProviderCapability::Filtering));
        self::assertFalse($provider->supports(ProviderCapability::Highlighting));
    }
}
