<?php

namespace Tahadudhiya\SearchKit\errors;

/**
 * A document could not be built from an element. The cause is logged; the message is safe to show.
 */
class DocumentException extends SearchKitException
{
    public function getName(): string
    {
        return 'Search Document Exception';
    }
}
