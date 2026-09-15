<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use Tahadudhiya\SearchKit\base\SearchProviderInterface;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\services\Providers;

class StubProviders extends Providers
{
    public SearchProviderInterface $provider;

    public function getProviderForIndex(SearchIndex $index): SearchProviderInterface
    {
        return $this->provider;
    }
}
