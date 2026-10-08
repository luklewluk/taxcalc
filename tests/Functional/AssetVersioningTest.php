<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Static files are served with a year-long, immutable cache, so every page
 * must reference them by a URL that changes with their content.
 */
final class AssetVersioningTest extends WebTestCase
{
    public function testEveryStylesheetAndScriptCarriesTheHashOfItsContent(): void
    {
        $client = static::createClient();

        foreach (['/', '/kalkulator'] as $page) {
            $crawler = $client->request('GET', $page);
            $urls = [
                ...$crawler->filter('link[rel="stylesheet"]')->each(static fn (Crawler $node): string => (string) $node->attr('href')),
                ...$crawler->filter('script[src]')->each(static fn (Crawler $node): string => (string) $node->attr('src')),
            ];
            self::assertGreaterThanOrEqual(3, count($urls), $page);

            foreach ($urls as $url) {
                self::assertMatchesRegularExpression('#^/[\w/.-]+\.(css|js)\?v=[0-9a-f]{12}$#', $url, $page);
                $path = (string) parse_url($url, PHP_URL_PATH);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $file = dirname(__DIR__, 2).'/public'.$path;

                self::assertFileExists($file);
                self::assertSame(substr((string) hash_file('xxh128', $file), 0, 12), $query['v'] ?? null, $url);
            }
        }
    }
}
