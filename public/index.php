<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$applicationRoot = dirname(__DIR__);

/*
 * When this front controller is copied to Hostinger's public_html directory,
 * the Laravel application lives in the sibling ffspotless directory. Locally
 * and in Docker, the normal ../ paths continue to be used.
 */
if (! is_file($applicationRoot.'/vendor/autoload.php')) {
    $hostingerApplicationRoot = dirname(__DIR__).DIRECTORY_SEPARATOR.'ffspotless';

    if (is_file($hostingerApplicationRoot.'/vendor/autoload.php')) {
        $applicationRoot = $hostingerApplicationRoot;
    }
}

header('Link: </manifest.webmanifest>; rel="manifest"', false);

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $applicationRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $applicationRoot.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $applicationRoot.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
