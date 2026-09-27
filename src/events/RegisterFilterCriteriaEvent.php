<?php

namespace Tahadudhiya\SearchKit\events;

use craft\base\Event;

/**
 * The seam an integration adds element query criteria through, so a filter can reach an element
 * type's own query parameters without the provider knowing which plugin defines them.
 */
class RegisterFilterCriteriaEvent extends Event
{
    /** @var string The element type class the criteria are being collected for. */
    public string $elementType = '';

    /** @var string[] The element query criteria a filter may name on this element type. */
    public array $criteria = [];
}
