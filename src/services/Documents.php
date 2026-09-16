<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\helpers\ElementHelper;
use Tahadudhiya\SearchKit\errors\DocumentException;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;

/**
 * Turns a Craft element into a SearchKit document. This is the only place that knows how a Craft
 * element stores its values, which is what keeps that knowledge out of every provider.
 */
class Documents extends Component
{
    /**
     * Builds the document an index would store for this element, containing only the searchable
     * values the index is configured for.
     *
     * @throws DocumentException if a configured value could not be read.
     */
    public function buildDocument(SearchIndex $index, ElementInterface $element): SearchDocument
    {
        $document = new SearchDocument([
            'elementId' => (int)$element->id,
            'siteId' => (int)$element->siteId,
            'elementType' => $element::class,
            'indexHandle' => $index->handle,
        ]);

        $document->setSource($element);

        $attributes = ElementHelper::searchableAttributes($element);
        $fieldLayout = $element->getFieldLayout();

        foreach ($index->getEnabledFields($element::class) as $field) {
            $customField = $fieldLayout?->getFieldByHandle($field->handle);

            if ($customField !== null) {
                $document->setField($field->handle, $this->customFieldKeywords($customField, $element), $field->weight);
                continue;
            }

            // A handle that is neither a searchable attribute nor on this element's layout simply
            // does not apply to it — other elements of the same type may still carry it.
            if (in_array($field->handle, $attributes, true)) {
                $document->setField($field->handle, $this->attributeKeywords($element, $field->handle), $field->weight);
            }
        }

        return $document;
    }

    /**
     * Craft fields know how to reduce their own value to keywords, so SearchKit never guesses.
     *
     * @throws DocumentException
     */
    private function customFieldKeywords(FieldInterface $field, ElementInterface $element): string
    {
        try {
            return $field->getSearchKeywords($element->getFieldValue($field->handle), $element);
        } catch (Throwable $e) {
            throw $this->extractionFailed($element, $field->handle, $e);
        }
    }

    /**
     * @throws DocumentException
     */
    private function attributeKeywords(ElementInterface $element, string $attribute): string
    {
        try {
            return $element->getSearchKeywords($attribute);
        } catch (Throwable $e) {
            throw $this->extractionFailed($element, $attribute, $e);
        }
    }

    /**
     * An empty value is content; a value that could not be read is a failure, so it is never
     * quietly indexed as an empty one.
     */
    private function extractionFailed(ElementInterface $element, string $handle, Throwable $e): DocumentException
    {
        Craft::error(
            "Could not read keywords from “{$handle}” on element {$element->id}: {$e->getMessage()}",
            SearchKit::LOG_CATEGORY,
        );

        return new DocumentException("Could not read the “{$handle}” field of element {$element->id}.", 0, $e);
    }
}
