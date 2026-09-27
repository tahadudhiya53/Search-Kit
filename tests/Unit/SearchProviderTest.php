<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
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
        $provider->deleteDocument(
            new SearchIndex(['handle' => 'siteSearch']),
            SearchDocument::forDeletion(1, 1, 'craft\\elements\\Entry', 'siteSearch'),
        );
    }

    public function testCountingIsRejectedWhenNotDeclared(): void
    {
        $provider = new MinimalProvider();

        $this->expectException(UnsupportedCapabilityException::class);
        $provider->facets(
            SearchQuery::create('siteSearch', 'boots', ['facets' => 'sectionId']),
            new SearchIndex(['handle' => 'siteSearch']),
        );
    }

    public function testCraftProviderDoesNotClaimCapabilitiesCraftLacks(): void
    {
        $provider = new CraftProvider();

        self::assertTrue($provider->supports(ProviderCapability::Search));
        self::assertTrue($provider->supports(ProviderCapability::Filtering));
        self::assertTrue($provider->supports(ProviderCapability::Sorting));

        // Craft scores and stores keywords itself, so these would be claims SearchKit cannot keep.
        self::assertFalse($provider->supports(ProviderCapability::FieldWeighting));
        self::assertFalse($provider->supports(ProviderCapability::Highlighting));
        self::assertFalse($provider->supports(ProviderCapability::Deleting));
        self::assertFalse($provider->supports(ProviderCapability::Rebuilding));
    }
}
