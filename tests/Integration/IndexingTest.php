<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * Craft treats a null field list as “index everything”, so these pin down what SearchKit sends it,
 * and that an index never indexes content from outside the sites it covers.
 */
class IndexingTest extends IntegrationTestCase
{
    public function testIndexingAnElementSucceeds(): void
    {
        $entry = $this->anEntry();
        $index = $this->indexWithFields([Entry::class => 'title']);

        (new CraftProvider())->indexElement($index, $entry);

        self::assertGreaterThan(0, $this->keywordRowCount($entry->id, $entry->siteId));
    }

    public function testAScopedIndexAcceptsAnElementFromItsOwnSite(): void
    {
        $siteId = $this->sitesWithEntries()[0];
        $entry = $this->anEntry($siteId);
        $index = $this->indexWithFields([Entry::class => 'title'], $siteId);

        (new CraftProvider())->indexElement($index, $entry);

        self::assertGreaterThan(0, $this->keywordRowCount($entry->id, $entry->siteId));
    }

    public function testAScopedIndexRejectsAnElementFromAnotherSite(): void
    {
        [$indexSiteId, $otherSiteId] = $this->sitesWithEntries();
        $entry = $this->anEntry($otherSiteId);
        $index = $this->indexWithFields([Entry::class => 'title'], $indexSiteId);

        $before = $this->keywordRowCount($entry->id, $entry->siteId);

        try {
            (new CraftProvider())->indexElement($index, $entry);
            self::fail('An element outside the index site scope should be rejected.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('does not cover', $e->getMessage());
        }

        self::assertSame($before, $this->keywordRowCount($entry->id, $entry->siteId));
    }

    public function testAnAllSiteIndexAcceptsElementsFromEverySite(): void
    {
        $index = $this->indexWithFields([Entry::class => 'title']);

        foreach ($this->sitesWithEntries() as $siteId) {
            $entry = $this->anEntry($siteId);

            (new CraftProvider())->indexElement($index, $entry);

            self::assertGreaterThan(0, $this->keywordRowCount($entry->id, $entry->siteId));
        }
    }

    public function testAnElementTypeWithNoConfiguredFieldsIsRejectedRatherThanIndexedWholesale(): void
    {
        $entry = $this->anEntry();
        $index = $this->indexWithFields([Asset::class => 'title']);

        $before = $this->keywordRowCount($entry->id, $entry->siteId);

        try {
            (new CraftProvider())->indexElement($index, $entry);
            self::fail('Indexing an element type the index does not cover should be rejected.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('not configured for this element type', $e->getMessage());
            // The class name belongs in the log, not in a message a user could see.
            self::assertStringNotContainsString(Entry::class, $e->getMessage());
        }

        self::assertSame($before, $this->keywordRowCount($entry->id, $entry->siteId));
    }

    public function testDisablingEveryFieldIsTreatedAsNoConfigurationRatherThanAllFields(): void
    {
        $entry = $this->anEntry();
        $index = $this->indexWithFields([Entry::class => 'title']);

        foreach ($this->plugin()->getSearchableFields()->getFieldsByIndexId($index->id) as $field) {
            $field->enabled = false;
            self::assertTrue($this->plugin()->getSearchableFields()->saveField($field));
        }

        $index->setFields($this->plugin()->getSearchableFields()->getFieldsByIndexId($index->id));

        $before = $this->keywordRowCount($entry->id, $entry->siteId);

        $this->expectException(ProviderException::class);

        try {
            (new CraftProvider())->indexElement($index, $entry);
        } finally {
            self::assertSame($before, $this->keywordRowCount($entry->id, $entry->siteId));
        }
    }

    public function testAnIndexWithNoFieldsAtAllCannotBeSearched(): void
    {
        $index = $this->persistIndex($this->newIndex());
        $this->plugin()->getSearchableFields()->attachFields($index);

        $this->expectException(ProviderException::class);
        (new CraftProvider())->indexElement($index, $this->anEntry());
    }

    private function anEntry(?int $siteId = null): Entry
    {
        $siteId ??= Craft::$app->getSites()->getPrimarySite()->id;
        $entry = Entry::find()->siteId($siteId)->status(null)->one();

        if ($entry === null) {
            self::markTestSkipped("This project has no entries in site $siteId.");
        }

        return $entry;
    }

    /**
     * @return int[]
     */
    private function sitesWithEntries(): array
    {
        $siteIds = array_values(array_filter(
            Craft::$app->getSites()->getAllSiteIds(),
            static fn(int $siteId) => Entry::find()->siteId($siteId)->status(null)->exists(),
        ));

        if (count($siteIds) < 2) {
            self::markTestSkipped('This project needs entries in at least two sites.');
        }

        return $siteIds;
    }

    /**
     * @param array<class-string,string> $fields
     */
    private function indexWithFields(array $fields, ?int $siteId = null): SearchIndex
    {
        $index = $this->newIndex();
        $index->siteId = $siteId;
        $this->persistIndex($index);

        foreach ($fields as $elementType => $handle) {
            $field = new SearchableField([
                'indexId' => $index->id,
                'elementType' => $elementType,
                'handle' => $handle,
                'weight' => 5,
            ]);

            self::assertTrue($this->plugin()->getSearchableFields()->saveField($field));
        }

        $this->plugin()->getSearchableFields()->attachFields($index);

        return $index;
    }

    private function keywordRowCount(int $elementId, int $siteId): int
    {
        return (int)(new Query())
            ->from([CraftTable::SEARCHINDEX])
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->count();
    }
}
