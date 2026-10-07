<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** TaxCalc.pl: name, mark, icons and domain metadata - all served locally. */
final class BrandTest extends WebTestCase
{
    public function testThePagesCarryTheNameAndTheMark(): void
    {
        $client = static::createClient();

        foreach (['/', '/kalkulator'] as $path) {
            $crawler = $client->request('GET', $path);

            self::assertStringContainsString('TaxCalc.pl', $crawler->filter('title')->text(), $path);
            self::assertStringContainsString('TaxCalc.pl', $crawler->filter('.site-header__brand')->text(), $path);

            $mark = $crawler->filter('.site-header__brand img.brand-mark');
            self::assertCount(1, $mark, $path);
            self::assertSame('', $mark->attr('alt'), 'The name next to it is the accessible label.');
            self::assertPublicFile((string) $mark->attr('src'));

            $icons = $crawler->filter('head link[rel="icon"], head link[rel="apple-touch-icon"]');
            self::assertGreaterThanOrEqual(2, $icons->count(), $path);
            $icons->each(static fn (Crawler $icon) => self::assertPublicFile((string) $icon->attr('href')));
        }
    }

    public function testTheErrorPageAndTheReportUseTheIconToo(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/nie-ma-takiej-strony');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $crawler->filter('link[rel="icon"][href^="data:"]')->count());
        self::assertGreaterThan(0, $crawler->filter('link[rel="icon"]')->count());
    }

    public function testTheDomainMetadataPointsAtTheConfiguredPublicUrl(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kalkulator');

        self::assertSame('https://taxcalc.pl/kalkulator', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('https://taxcalc.pl/kalkulator', $crawler->filter('meta[property="og:url"]')->attr('content'));
        self::assertSame('TaxCalc.pl', $crawler->filter('meta[property="og:site_name"]')->attr('content'));
        self::assertSame('https://taxcalc.pl/img/brand/og-image.png', $crawler->filter('meta[property="og:image"]')->attr('content'));
        self::assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'));
        self::assertPublicFile('/img/brand/og-image.png');
    }

    public function testThePaletteIsTealAndHasNoLimeLeft(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/public/css/app.css');

        self::assertMatchesRegularExpression('/--brand:\s*light-dark\(#087F7C,\s*#35CFC9\)/i', $css);
        self::assertStringNotContainsString('--lime', $css);
        self::assertStringNotContainsString('bff055', strtolower($css));
    }

    private static function assertPublicFile(string $url): void
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        self::assertStringStartsWith('/', $path, $url);
        self::assertFileExists(dirname(__DIR__, 2).'/public'.$path);
    }
}
