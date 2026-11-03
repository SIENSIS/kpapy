<?php

namespace Kpapy\Demo\Controllers;

use Kpapy\Config\Kpapy as KpapyConfig;
use Kpapy\Demo\Demo;
use Kpapy\OpenApi\DocsController;

/**
 * Documentation of the demo API only, with the demo's title and settings.
 */
class DemoDocs extends DocsController
{
    protected function prefix(): ?string
    {
        return Demo::API;
    }

    protected function kpapy(): KpapyConfig
    {
        return Demo::config();
    }
}
