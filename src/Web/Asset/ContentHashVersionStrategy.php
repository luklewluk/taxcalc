<?php

declare(strict_types=1);

namespace App\Web\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;

/**
 * Versions every asset() URL by its content: `css/app.css?v=<12 hex>`.
 *
 * The web server caches static files for a year and marks them immutable. A
 * fixed URL would then keep serving yesterday's stylesheet and script under
 * today's markup; a URL that changes with the file cannot. An unchanged file
 * keeps its URL - and its cache - across deploys, which a release-based
 * version would not.
 */
final class ContentHashVersionStrategy implements VersionStrategyInterface
{
    /** @var array<string, string> */
    private array $versions = [];

    public function __construct(private readonly string $publicDir)
    {
    }

    public function getVersion(string $path): string
    {
        return $this->versions[$path] ??= $this->hash($path);
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);

        return '' === $version ? $path : $path.'?v='.$version;
    }

    private function hash(string $path): string
    {
        $root = realpath($this->publicDir);
        $file = $root ? realpath($root.'/'.ltrim($path, '/')) : false;

        // Only a real file inside public/ - never a path that climbs out of it.
        if (false === $root || false === $file || !is_file($file) || !str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            return '';
        }

        $hash = hash_file('xxh128', $file);

        return false === $hash ? '' : substr($hash, 0, 12);
    }
}
