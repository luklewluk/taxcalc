<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The workbench posts every row back as form fields, and PHP drops variables
 * past max_input_vars silently. The limit is set in three places - the Docker
 * image, the PHP-FPM pool example and public/.user.ini, which a deploy carries
 * to a server whose pool was never configured - and they must agree.
 */
final class PhpLimitsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function settings(): iterable
    {
        yield 'max_input_vars' => ['max_input_vars'];
        yield 'post_max_size' => ['post_max_size'];
        yield 'upload_max_filesize' => ['upload_max_filesize'];
    }

    #[DataProvider('settings')]
    public function testEveryPlaceThatSetsTheLimitAgrees(string $setting): void
    {
        $root = dirname(__DIR__, 3);
        $docker = self::ini($root.'/docker/php.ini', $setting);

        self::assertNotNull($docker);
        self::assertSame($docker, self::ini($root.'/public/.user.ini', $setting), 'public/.user.ini');
        self::assertSame($docker, self::pool($root.'/deploy/php-fpm/taxcalc.conf.example', $setting), 'PHP-FPM pool example');
    }

    public function testTheRowCapFitsInsideTheLimit(): void
    {
        $limit = (int) self::ini(dirname(__DIR__, 3).'/public/.user.ini', 'max_input_vars');

        self::assertGreaterThanOrEqual(120000, $limit);
    }

    private static function ini(string $path, string $setting): ?string
    {
        self::assertFileExists($path);
        $matched = preg_match('/^\s*'.preg_quote($setting, '/').'\s*=\s*(\S+)\s*$/m', (string) file_get_contents($path), $match);

        return 1 === $matched ? $match[1] : null;
    }

    private static function pool(string $path, string $setting): ?string
    {
        self::assertFileExists($path);
        $matched = preg_match('/^\s*php_admin_value\['.preg_quote($setting, '/').'\]\s*=\s*(\S+)\s*$/m', (string) file_get_contents($path), $match);

        return 1 === $matched ? $match[1] : null;
    }
}
