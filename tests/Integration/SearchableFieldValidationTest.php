<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use Tahadudhiya\SearchKit\models\SearchableField;

/**
 * Nothing a caller posts is taken on trust: a field has to be one this element type can actually
 * be indexed on, whether the request came from the control panel, the console or PHP.
 */
class SearchableFieldValidationTest extends IntegrationTestCase
{
    public function testABuiltInSearchableAttributeIsAccepted(): void
    {
        self::assertTrue($this->validate([$this->field(Entry::class, 'title')]));
        self::assertTrue($this->validate([$this->field(Entry::class, 'slug')]));
    }

    public function testACustomFieldOnTheElementTypesLayoutsIsAccepted(): void
    {
        [$elementType, $handle] = $this->aCustomField();

        self::assertTrue($this->validate([$this->field($elementType, $handle)]));
    }

    public function testACustomFieldCraftDoesNotIndexIsNotOffered(): void
    {
        $unsearchable = array_filter(
            Craft::$app->getFields()->getAllFields(),
            static fn(FieldInterface $field) => !$field->searchable,
        );

        if ($unsearchable === []) {
            self::markTestSkipped('Every custom field in this project is searchable.');
        }

        $handles = $this->plugin()->getSearchableFields()->getAvailableHandles(Entry::class);

        foreach ($unsearchable as $field) {
            self::assertNotContains($field->handle, $handles);
            self::assertFalse($this->validate([$this->field(Entry::class, $field->handle)]));
        }
    }

    public function testAHandleThatDoesNotExistIsRejected(): void
    {
        $field = $this->field(Entry::class, 'notAFieldAnywhere');

        self::assertFalse($this->validate([$field]));
        self::assertArrayHasKey('handle', $field->getErrors());
    }

    public function testAHandleValidForAnotherElementTypeIsRejected(): void
    {
        $handle = $this->aHandleOnlyOn(Asset::class, Entry::class);
        $field = $this->field(Entry::class, $handle);

        self::assertFalse($this->validate([$field]));
        self::assertArrayHasKey('handle', $field->getErrors());

        // The same handle is fine on the element type that actually has it.
        self::assertTrue($this->validate([$this->field(Asset::class, $handle)]));
    }

    public function testAnElementTypeSearchKitDoesNotIndexIsRejected(): void
    {
        $field = $this->field(GlobalSet::class, 'title');

        self::assertFalse($this->validate([$field]));
        self::assertArrayHasKey('elementType', $field->getErrors());
    }

    public function testSomethingThatIsNotAnElementTypeIsRejected(): void
    {
        $field = $this->field(self::class, 'title');

        self::assertFalse($this->validate([$field]));
        self::assertArrayHasKey('elementType', $field->getErrors());
    }

    public function testTheSameHandleTwiceForOneElementTypeIsRejected(): void
    {
        $fields = [$this->field(Entry::class, 'title'), $this->field(Entry::class, 'title')];

        self::assertFalse($this->validate($fields));
        self::assertArrayHasKey('handle', $fields[1]->getErrors());
    }

    public function testTheSameHandleOnDifferentElementTypesIsFine(): void
    {
        self::assertTrue($this->validate([
            $this->field(Entry::class, 'title'),
            $this->field(Asset::class, 'title'),
        ]));
    }

    public function testAWeightOutsideTheAllowedRangeIsRejected(): void
    {
        $tooHigh = $this->field(Entry::class, 'title');
        $tooHigh->weight = SearchableField::MAX_WEIGHT + 1;

        $negative = $this->field(Entry::class, 'slug');
        $negative->weight = -1;

        self::assertFalse($this->validate([$tooHigh]));
        self::assertArrayHasKey('weight', $tooHigh->getErrors());
        self::assertFalse($this->validate([$negative]));
        self::assertArrayHasKey('weight', $negative->getErrors());
    }

    public function testADisabledFieldIsStillValidConfiguration(): void
    {
        $field = $this->field(Entry::class, 'title');
        $field->enabled = false;

        self::assertTrue($this->validate([$field]));
    }

    public function testAnInvalidFieldIsRejectedByTheServiceNotJustTheControlPanel(): void
    {
        $index = $this->persistIndex($this->newIndex());

        $saved = $this->plugin()->getSearchableFields()->saveFieldsForIndex($index, [
            $this->field(Entry::class, 'notAFieldAnywhere'),
        ]);

        self::assertFalse($saved);
        self::assertSame([], $this->plugin()->getSearchableFields()->getFieldsByIndexId((int)$index->id));
    }

    public function testTheOfferedHandlesAreExactlyTheOnesASaveAccepts(): void
    {
        $fields = $this->plugin()->getSearchableFields();

        foreach ($fields->getIndexableElementTypes() as $elementType) {
            $handles = $fields->getAvailableHandles($elementType);
            self::assertNotSame([], $handles, "$elementType offers no searchable handles.");

            foreach ($handles as $handle) {
                self::assertTrue(
                    $this->validate([$this->field($elementType, $handle)]),
                    "“{$handle}” is offered for {$elementType} but rejected on save.",
                );
            }
        }
    }

    /**
     * @param SearchableField[] $fields
     */
    private function validate(array $fields): bool
    {
        return $this->plugin()->getSearchableFields()->validateFields($fields);
    }

    private function field(string $elementType, string $handle): SearchableField
    {
        return new SearchableField([
            'indexId' => 1,
            'elementType' => $elementType,
            'handle' => $handle,
            'weight' => 5,
        ]);
    }

    /**
     * The first searchable custom field this project has on any indexable element type.
     *
     * @return array{class-string,string}
     */
    private function aCustomField(): array
    {
        $fields = $this->plugin()->getSearchableFields();

        foreach ($fields->getIndexableElementTypes() as $elementType) {
            $attributes = array_merge($elementType::searchableAttributes(), ['slug', 'title']);

            foreach ($fields->getAvailableHandles($elementType) as $handle) {
                if (!in_array($handle, $attributes, true)) {
                    return [$elementType, $handle];
                }
            }
        }

        self::markTestSkipped('This project has no searchable custom field on any indexable element type.');
    }

    /**
     * @param class-string $onlyOn
     * @param class-string $notOn
     */
    private function aHandleOnlyOn(string $onlyOn, string $notOn): string
    {
        $fields = $this->plugin()->getSearchableFields();
        $excluded = $fields->getAvailableHandles($notOn);

        foreach ($fields->getAvailableHandles($onlyOn) as $handle) {
            if (!in_array($handle, $excluded, true)) {
                return $handle;
            }
        }

        self::markTestSkipped("This project has no handle unique to $onlyOn.");
    }
}
