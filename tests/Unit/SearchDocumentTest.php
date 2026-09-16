<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\models\SearchDocument;

class SearchDocumentTest extends TestCase
{
    public function testFieldsCarryTheirOwnWeights(): void
    {
        $document = new SearchDocument(['elementId' => 7, 'siteId' => 1]);
        $document->setField('title', 'winter boots', 25);
        $document->setField('body', 'warm and waterproof');

        self::assertSame(['title' => 'winter boots', 'body' => 'warm and waterproof'], $document->getFields());
        self::assertSame(['title', 'body'], $document->getFieldHandles());
        self::assertSame(25, $document->getWeight('title'));
        self::assertSame(1, $document->getWeight('body'));
        self::assertNull($document->getWeight('slug'));
    }

    public function testWeightsAreExposedAsPlainHandleToIntegerPairs(): void
    {
        $document = new SearchDocument();
        $document->setField('title', 'a', 10);
        $document->setField('summary', 'b', 3);

        self::assertSame(['title' => 10, 'summary' => 3], $document->getWeights());
    }

    public function testSettingAFieldTwiceReplacesIt(): void
    {
        $document = new SearchDocument();
        $document->setField('title', 'first', 2);
        $document->setField('title', 'second', 8);

        self::assertSame(['title' => 'second'], $document->getFields());
        self::assertSame(8, $document->getWeight('title'));
    }

    public function testAnEmptyDocumentIsRecognisable(): void
    {
        self::assertTrue((new SearchDocument())->isEmpty());

        $document = new SearchDocument();
        $document->setField('title', '');

        // A configured field with no content still counts: the provider has keywords to clear.
        self::assertFalse($document->isEmpty());
    }

    public function testADeletionDocumentIdentifiesTheElementWithoutContent(): void
    {
        $document = SearchDocument::forDeletion(12, 3, 'craft\elements\Entry', 'siteSearch');

        self::assertSame(12, $document->elementId);
        self::assertSame(3, $document->siteId);
        self::assertSame('craft\elements\Entry', $document->elementType);
        self::assertSame('siteSearch', $document->indexHandle);
        self::assertNull($document->getSource());
        self::assertTrue($document->isEmpty());
    }
}
