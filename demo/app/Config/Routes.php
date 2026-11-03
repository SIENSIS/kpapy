<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// This project only hosts the demo. Its routes (/kpapy-demo) are registered
// by the kpapy library itself after "php spark kpapy:demo install".
$routes->addRedirect('/', 'kpapy-demo');
