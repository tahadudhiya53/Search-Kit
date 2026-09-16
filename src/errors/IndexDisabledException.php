<?php

namespace Tahadudhiya\SearchKit\errors;

class IndexDisabledException extends SearchKitException
{
    public function getName(): string
    {
        return 'Search Index Disabled';
    }
}
