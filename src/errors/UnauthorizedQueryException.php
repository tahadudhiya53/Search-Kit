<?php

namespace Tahadudhiya\SearchKit\errors;

/**
 * A search the current user is not allowed to run, such as an anonymous visitor asking for content
 * that is not published.
 */
class UnauthorizedQueryException extends SearchKitException
{
    public function getName(): string
    {
        return 'Unauthorized Search Query';
    }
}
