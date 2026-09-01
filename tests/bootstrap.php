<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// The test kernel runs with debug disabled so that error pages, logging and
// caching behave the way they do in production - which is exactly what the
// functional tests assert. The trade-off is that a non-debug container is not
// invalidated when source files change, so it is rebuilt from scratch on every
// run. That costs a fraction of a second and removes any chance of a test
// passing (or failing) against a stale container.
$cacheDir = dirname(__DIR__).'/var/cache/test';
if (is_dir($cacheDir)) {
    (new Filesystem())->remove($cacheDir);
}
