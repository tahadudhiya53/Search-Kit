<?php

namespace Tahadudhiya\SearchKit\errors;

class IndexNotFoundException extends SearchKitException
{
    public function getName(): string
    {
        return 'Search Index Not Found';
    }
}
