<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\models\Section;
use Tahadudhiya\SearchKit\enums\IndexOperationStatus;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\Tests\Support\RecordingProvider;

/**
 * For tests that make real content changes and follow them through to a provider.
 */
abstract class ContentTestCase extends IntegrationTestCase
{
    /** @var Entry[] */
    protected array $createdEntries = [];

    protected int $queueBaseline = 0;

    protected function setUp(): void
    {
        parent::setUp();

        RecordingProvider::reset();
        $this->queueBaseline = (int)((new Query())->from([CraftTable::QUEUE])->max('[[id]]') ?? 0);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdEntries as $entry) {
            Craft::$app->getElements()->deleteElement($entry, true);
        }

        $this->createdEntries = [];

        // Jobs this test made SearchKit push; left behind they would only find a deleted index.
        Db::delete(CraftTable::QUEUE, [
            'and',
            ['>', 'id', $this->queueBaseline],
            ['like', 'description', 'search index'],
        ]);

        RecordingProvider::reset();

        parent::tearDown();
    }

    protected function recordingIndex(?int $siteId = null): SearchIndex
    {
        return $this->persistIndexWithFields(
            [Entry::class => 'title'],
            RecordingProvider::class,
            $siteId ?? $this->sectionSiteId(),
        );
    }

    /**
     * @return SearchDocument[]
     */
    protected function documentsFor(int|string|null $elementId): array
    {
        return array_values(array_filter(
            RecordingProvider::$indexed,
            static fn(SearchDocument $document) => $document->elementId === (int)$elementId,
        ));
    }

    protected function pendingCount(SearchIndex $index): int
    {
        return $this->plugin()->getIndexOperations()->countByStatus((int)$index->id, IndexOperationStatus::Pending);
    }

    protected function queuedJobCount(): int
    {
        return (int)(new Query())
            ->from([CraftTable::QUEUE])
            ->where(['>', 'id', $this->queueBaseline])
            ->andWhere(['like', 'description', 'search index'])
            ->count();
    }

    protected function createEntry(string $title, ?int $siteId = null): Entry
    {
        $section = $this->section();
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $section->getEntryTypes()[0]->id;
        $entry->siteId = $siteId ?? $this->sectionSiteId();
        $entry->title = $title;
        $entry->enabled = true;

        if (!Craft::$app->getElements()->saveElement($entry)) {
            self::fail('Could not create a test entry: ' . implode(' ', $entry->getErrorSummary(true)));
        }

        $this->createdEntries[] = $entry;

        return $entry;
    }

    protected function sectionSiteId(): int
    {
        foreach ($this->section()->getSiteSettings() as $siteSettings) {
            return (int)$siteSettings->siteId;
        }

        self::markTestSkipped('The test section is not enabled for any site.');
    }

    protected function aSiteOtherThan(int $siteId): int
    {
        foreach (Craft::$app->getSites()->getAllSiteIds() as $candidate) {
            if ((int)$candidate !== $siteId) {
                return (int)$candidate;
            }
        }

        self::markTestSkipped('This project has only one site.');
    }

    protected function section(): Section
    {
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($section->type !== Section::TYPE_SINGLE && $section->getEntryTypes() !== []) {
                return $section;
            }
        }

        self::markTestSkipped('This project has no section that new entries can be created in.');
    }
}
