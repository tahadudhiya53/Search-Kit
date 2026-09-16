<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\base\ElementInterface;
use craft\db\Table as CraftTable;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\AuthorizationCheckEvent;
use craft\helpers\Db;
use craft\models\CategoryGroup;
use craft\models\EntryType;
use craft\models\Section;
use craft\services\Elements;
use DateTime;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use yii\base\Event;

/**
 * For search tests that need real content with real custom fields, in every site a section covers.
 */
abstract class SearchContentTestCase extends ContentTestCase
{
    /** @var string A field this project's entries and categories can both be indexed and queried on. */
    protected const FIELD = 'searchKitTestKeyword';

    /** @var ElementInterface[] Elements to remove when the test finishes. */
    private array $createdElements = [];

    /** @var callable|null The handler standing in for a site that refuses a viewer. */
    private $denial = null;

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        if ($this->denial !== null) {
            Event::off(Elements::class, Elements::EVENT_AUTHORIZE_VIEW, $this->denial);
            $this->denial = null;
        }

        foreach ($this->createdElements as $element) {
            Craft::$app->getElements()->deleteElement($element, true);
        }

        $this->createdElements = [];

        parent::tearDown();
    }

    protected function adminUser(): User
    {
        $admin = User::find()->admin(true)->status(null)->one();

        if ($admin === null) {
            self::markTestSkipped('This project has no admin account to search as.');
        }

        return $admin;
    }

    /**
     * Stands in for a site that refuses this viewer, through Craft's own authorization check. This
     * project is a Solo installation, so a second account cannot exist to refuse instead.
     */
    protected function refuseViewing(int ...$elementIds): void
    {
        $this->denial = static function(AuthorizationCheckEvent $event) use ($elementIds) {
            if ($elementIds === [] || in_array((int)$event->element?->id, $elementIds, true)) {
                $event->authorized = false;
            }
        };

        Event::on(Elements::class, Elements::EVENT_AUTHORIZE_VIEW, $this->denial);
    }

    /**
     * A section whose entries carry the searchable custom fields these tests filter on.
     */
    protected function fieldSection(): Section
    {
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            foreach ($section->getEntryTypes() as $entryType) {
                if ($entryType->getFieldLayout()->getFieldByHandle(static::FIELD) !== null) {
                    return $section;
                }
            }
        }

        self::markTestSkipped('This project has no section carrying the “' . static::FIELD . '” field.');
    }

    protected function fieldEntryType(): EntryType
    {
        foreach ($this->fieldSection()->getEntryTypes() as $entryType) {
            if ($entryType->getFieldLayout()->getFieldByHandle(static::FIELD) !== null) {
                return $entryType;
            }
        }

        self::markTestSkipped('This project has no entry type carrying the “' . static::FIELD . '” field.');
    }

    protected function fieldCategoryGroup(): CategoryGroup
    {
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if ($group->getFieldLayout()->getFieldByHandle(static::FIELD) !== null) {
                return $group;
            }
        }

        self::markTestSkipped('This project has no category group carrying the “' . static::FIELD . '” field.');
    }

    protected function fieldSectionSiteId(): int
    {
        foreach ($this->fieldSection()->getSiteSettings() as $siteSettings) {
            return (int)$siteSettings->siteId;
        }

        self::markTestSkipped('The section under test is not enabled for any site.');
    }

    /**
     * @return int[] Every site the section under test covers.
     */
    protected function fieldSectionSiteIds(): array
    {
        return array_map(
            static fn($siteSettings) => (int)$siteSettings->siteId,
            array_values($this->fieldSection()->getSiteSettings()),
        );
    }

    /**
     * @param array<string,mixed> $values
     */
    protected function createPage(string $title, array $values = [], bool $enabled = true, ?DateTime $postDate = null): Entry
    {
        $entry = new Entry();
        $entry->sectionId = $this->fieldSection()->id;
        $entry->typeId = $this->fieldEntryType()->id;
        $entry->siteId = $this->fieldSectionSiteId();
        $entry->title = $title;
        $entry->enabled = $enabled;
        $entry->postDate = $postDate;
        $entry->setFieldValues($values);

        return $this->persist($entry);
    }

    /**
     * @param array<string,mixed> $values
     */
    protected function createCategory(string $title, array $values = []): Category
    {
        $category = new Category();
        $category->groupId = $this->fieldCategoryGroup()->id;
        $category->siteId = $this->fieldSectionSiteId();
        $category->title = $title;
        $category->setFieldValues($values);

        return $this->persist($category);
    }

    /**
     * @template T of ElementInterface
     * @param T $element
     * @return T
     */
    protected function persist(ElementInterface $element): ElementInterface
    {
        if (!Craft::$app->getElements()->saveElement($element)) {
            self::fail('Could not create test content: ' . implode(' ', $element->getErrorSummary(true)));
        }

        $this->createdElements[] = $element;

        // Craft indexes an element's keywords on save; this makes the test independent of when.
        Craft::$app->getSearch()->indexElementAttributes($element);

        return $element;
    }

    /**
     * Moves an element's creation date, so ordering tests are not left comparing equal timestamps.
     */
    protected function backdate(ElementInterface $element, string $modifier): void
    {
        Db::update(CraftTable::ELEMENTS, [
            'dateCreated' => Db::prepareDateForDb(new DateTime($modifier)),
        ], ['id' => $element->id]);
    }

    /**
     * @param array<string,mixed> $params
     */
    protected function searchIndex(string $handle, string $text, array $params = []): SearchResult
    {
        return $this->plugin()->getSearch()->search(SearchQuery::create($handle, $text, $params));
    }

    /**
     * @return int[]
     */
    protected function idsOf(SearchResult $result): array
    {
        return $result->getElementIds();
    }
}
