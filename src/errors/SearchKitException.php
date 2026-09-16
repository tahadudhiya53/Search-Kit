<?php

namespace Tahadudhiya\SearchKit\errors;

use yii\base\Exception;

/**
 * Base for every SearchKit failure, so callers can isolate SearchKit problems from Craft's.
 */
class SearchKitException extends Exception
{
    public function getName(): string
    {
        return 'SearchKit Exception';
    }
}
