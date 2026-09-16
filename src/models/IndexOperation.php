<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use DateTime;
use Tahadudhiya\SearchKit\enums\IndexOperationStatus;
use Tahadudhiya\SearchKit\enums\IndexOperationType;

/**
 * One piece of outstanding indexing work: an element, a site, and what has to happen to it.
 */
class IndexOperation extends Model
{
    public ?int $id = null;
    public ?int $indexId = null;
    public int $elementId = 0;
    public int $siteId = 0;
    public string $elementType = '';
    public IndexOperationType $operation = IndexOperationType::Index;
    public IndexOperationStatus $status = IndexOperationStatus::Pending;
    public int $attempts = 0;
    public ?string $error = null;

    /** @var string Identifies this exact intent; a newer intent for the same element replaces it. */
    public string $token = '';

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;
}
