<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use craft\elements\Asset;
use craft\elements\Entry;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;

class SearchIndexTest extends TestCase
{
    public function testValidIndexPasses(): void
    {
        self::assertTrue($this->createIndex()->validate());
    }

    public function testRejectsAClassThatIsNotAProvider(): void
    {
        $index = $this->createIndex();
        $index->provider = Entry::class;

        self::assertFalse($index->validate());
        self::assertArrayHasKey('provider', $index->getErrors());
    }

    public function testFieldWeightsAreKeyedByHandle(): void
    {
        $index = $this->createIndex();
        $index->setFields([
            $this->createField(Entry::class, 'title', 10),
            $this->createField(Entry::class, 'summary', 5),
            $this->createField(Entry::class, 'body', 2),
        ]);

        self::assertSame([
            'title' => 10,
            'summary' => 5,
            'body' => 2,
        ], $index->getFieldWeights());
    }

    public function testFieldWeightsCanBeScopedToAnElementType(): void
    {
        $index = $this->createIndex();
        $index->setFields([
            $this->createField(Entry::class, 'title', 10),
            $this->createField(Asset::class, 'filename', 4),
        ]);

        self::assertSame(['filename' => 4], $index->getFieldWeights(Asset::class));
        self::assertEqualsCanonicalizing([Entry::class, Asset::class], $index->getElementTypes());
    }

    public function testDisabledFieldsAreExcluded(): void
    {
        $index = $this->createIndex();
        $disabled = $this->createField(Entry::class, 'body', 2);
        $disabled->enabled = false;

        $index->setFields([$this->createField(Entry::class, 'title', 10), $disabled]);

        self::assertSame(['title' => 10], $index->getFieldWeights());
        self::assertSame([Entry::class], $index->getElementTypes());
    }

    public function testASharedHandleKeepsTheHighestWeightAcrossElementTypes(): void
    {
        // The table allows the same handle per element type, so the unscoped map has to pick one.
        $index = $this->createIndex();
        $index->setFields([
            $this->createField(Entry::class, 'title', 10),
            $this->createField(Asset::class, 'title', 3),
        ]);

        self::assertSame(['title' => 10], $index->getFieldWeights());
        self::assertSame(['title' => 3], $index->getFieldWeights(Asset::class));
    }

    public function testSiteScopeIsExplicit(): void
    {
        $allSites = $this->createIndex();
        $oneSite = $this->createIndex();
        $oneSite->siteId = 2;

        self::assertTrue($allSites->coversAllSites());
        self::assertTrue($allSites->coversSite(3));

        self::assertFalse($oneSite->coversAllSites());
        self::assertTrue($oneSite->coversSite(2));
        self::assertFalse($oneSite->coversSite(3));
    }

    public function testFieldRejectsAnElementTypeThatIsNotAnElement(): void
    {
        $field = $this->createField(CraftProvider::class, 'title', 1);

        self::assertFalse($field->validate());
        self::assertArrayHasKey('elementType', $field->getErrors());
    }

    public function testFieldRejectsANegativeWeight(): void
    {
        $field = $this->createField(Entry::class, 'title', -1);

        self::assertFalse($field->validate());
        self::assertArrayHasKey('weight', $field->getErrors());
    }

    private function createIndex(): SearchIndex
    {
        return new SearchIndex([
            'name' => 'Site Search',
            'handle' => 'siteSearch',
            'provider' => CraftProvider::class,
        ]);
    }

    private function createField(string $elementType, string $handle, int $weight): SearchableField
    {
        return new SearchableField([
            'indexId' => 1,
            'elementType' => $elementType,
            'handle' => $handle,
            'weight' => $weight,
        ]);
    }
}
