<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use RuntimeException;
use Tahadudhiya\SearchKit\errors\DocumentException;
use Tahadudhiya\SearchKit\Tests\Support\ThrowingEntry;
use Tahadudhiya\SearchKit\Tests\Support\ThrowingFieldLayout;

/**
 * The document builder is the boundary between Craft's element storage and every provider, so
 * these pin down exactly what crosses it.
 */
class DocumentBuilderTest extends IntegrationTestCase
{
    public function testAConfiguredAttributeBecomesADocumentFieldWithItsWeight(): void
    {
        $entry = $this->anEntry();
        $index = $this->persistIndexWithFields([Entry::class => 'title']);

        $document = $this->plugin()->getDocuments()->buildDocument($index, $entry);

        self::assertSame(['title'], $document->getFieldHandles());
        self::assertSame(5, $document->getWeight('title'));
        self::assertSame((int)$entry->id, $document->elementId);
        self::assertSame((int)$entry->siteId, $document->siteId);
        self::assertSame(Entry::class, $document->elementType);
        self::assertSame($index->handle, $document->indexHandle);
        self::assertSame($entry, $document->getSource());
    }

    public function testKeywordsComeFromCraftRatherThanTheRawValue(): void
    {
        $entry = $this->anEntry();
        $index = $this->persistIndexWithFields([Entry::class => 'title']);

        $document = $this->plugin()->getDocuments()->buildDocument($index, $entry);

        self::assertSame($entry->getSearchKeywords('title'), $document->getValue('title'));
    }

    public function testAnElementTypeTheIndexIsNotConfiguredForProducesAnEmptyDocument(): void
    {
        $index = $this->persistIndexWithFields([Asset::class => 'title']);

        $document = $this->plugin()->getDocuments()->buildDocument($index, $this->anEntry());

        self::assertTrue($document->isEmpty());
    }

    public function testAHandleTheElementDoesNotCarryIsSkippedRatherThanFailing(): void
    {
        // Saved without validation: this stands in for a handle that exists on some of the element
        // type's layouts but not on this particular element's.
        $index = $this->persistIndexWithFields([Entry::class => 'notAFieldOnThisElement'], validate: false);

        $document = $this->plugin()->getDocuments()->buildDocument($index, $this->anEntry());

        self::assertTrue($document->isEmpty());
    }

    public function testDisabledFieldsAreNotExtracted(): void
    {
        $index = $this->persistIndexWithFields([Entry::class => 'title']);

        foreach ($index->getFields() as $field) {
            $field->enabled = false;
        }

        $document = $this->plugin()->getDocuments()->buildDocument($index, $this->anEntry());

        self::assertTrue($document->isEmpty());
    }

    public function testAnAttributeThatCannotBeReadIsAFailureRatherThanAnEmptyValue(): void
    {
        $index = $this->persistIndexWithFields([ThrowingEntry::class => 'title'], validate: false);
        $element = $this->unreadableEntry();

        try {
            $this->plugin()->getDocuments()->buildDocument($index, $element);
            self::fail('An extraction failure should not produce a document.');
        } catch (DocumentException $e) {
            self::assertStringContainsString('Could not read the “title” field', $e->getMessage());
            // The underlying cause belongs in the log, not in a message someone could be shown.
            self::assertStringNotContainsString('exploded', $e->getMessage());
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    public function testACustomFieldThatCannotBeReadIsAFailureRatherThanAnEmptyValue(): void
    {
        $index = $this->persistIndexWithFields([ThrowingEntry::class => 'title'], validate: false);
        $element = $this->unreadableEntry();
        $element->layout = new ThrowingFieldLayout();

        $this->expectException(DocumentException::class);
        $this->plugin()->getDocuments()->buildDocument($index, $element);
    }

    public function testAFieldWithNoContentIsStillIndexedAsAnEmptyValue(): void
    {
        $entry = $this->anEntry();
        $index = $this->persistIndexWithFields([Entry::class => 'title']);

        $document = $this->plugin()->getDocuments()->buildDocument($index, $entry);

        // An empty value is content; only an exception is a failure.
        self::assertContains('title', $document->getFieldHandles());
        self::assertIsString($document->getValue('title'));
    }

    private function unreadableEntry(): ThrowingEntry
    {
        $entry = new ThrowingEntry();
        $entry->id = 999999;
        $entry->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        return $entry;
    }

    private function anEntry(): Entry
    {
        $entry = Entry::find()
            ->siteId(Craft::$app->getSites()->getPrimarySite()->id)
            ->status(null)
            ->one();

        if ($entry === null) {
            self::markTestSkipped('This project has no entries.');
        }

        return $entry;
    }
}
