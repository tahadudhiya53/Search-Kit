<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use craft\base\ElementInterface;
use Tahadudhiya\SearchKit\errors\DocumentException;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\services\Documents;

/**
 * A document builder that cannot read anything, to prove extraction failures are never mistaken
 * for successfully indexed empty documents.
 */
class FailingDocuments extends Documents
{
    public function buildDocument(SearchIndex $index, ElementInterface $element): SearchDocument
    {
        throw new DocumentException("Could not read the “title” field of element {$element->id}.");
    }
}
