<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The colour theme follows the operating system unless the user picks one. The
 * pick is applied by a blocking script in <head>, so it must load before the
 * stylesheet on every page that renders the stylesheet - otherwise the page
 * paints in the system theme first and flips a moment later.
 */
final class ThemeSwitchTest extends WebTestCase
{
    private const string DIVIDENDS = <<<'CSV'
        name,country,currency,date,amount,tax_paid
        AAA,US,USD,2025-04-02,100.00,30.00
        CSV;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testEveryLayoutAppliesTheThemeBeforeTheStylesheet(): void
    {
        $client = static::createClient();

        self::assertThemeScriptPrecedesStylesheet($client->request('GET', '/'));
        self::assertThemeScriptPrecedesStylesheet($client->request('GET', '/kalkulator'));

        $client->request('GET', '/nie-ma-takiej-strony');
        self::assertResponseStatusCodeSame(404);
        self::assertThemeScriptPrecedesStylesheet($client->getCrawler());

        $workbench = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);
        $payload = $workbench->filter('form[data-workbench]')->form()->getPhpValues();
        self::assertThemeScriptPrecedesStylesheet($client->request('POST', '/kalkulator/raport', $payload));
    }

    public function testHeaderShipsOneHiddenRoundToggleThatStartsOnTheSystemTheme(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(0, $crawler->filter('[data-role="theme-switch"], [data-theme-choice]'));

        $toggle = $crawler->filter('.site-header button[data-role="theme-toggle"]');
        self::assertCount(1, $toggle);
        self::assertSame('button', $toggle->attr('type'));
        self::assertNotNull($toggle->attr('hidden'), 'Without JavaScript the toggle cannot do anything.');
        self::assertSame('auto', $toggle->attr('data-theme-state'));
        self::assertNotEmpty($toggle->attr('aria-label'));

        $icons = $toggle->filter('svg');
        self::assertSame(['auto', 'light', 'dark'], $icons->each(
            static fn (Crawler $icon): string => (string) $icon->attr('data-icon'),
        ));
        $icons->each(static fn (Crawler $icon) => self::assertSame('true', $icon->attr('aria-hidden')));
    }

    public function testTokensFollowTheColorSchemeAndPaperIsAlwaysLight(): void
    {
        $css = self::assetContents('css/app.css');
        $print = self::assetContents('css/print.css');
        $script = self::assetContents('js/theme.js');

        // One source per token: no second, media-gated copy of the palette.
        self::assertStringNotContainsString('prefers-color-scheme', $css);
        self::assertMatchesRegularExpression('/--canvas:\s*light-dark\(/', $css);
        self::assertMatchesRegularExpression('/:root\[data-theme="light"\]\s*\{\s*color-scheme:\s*light;/', $css);
        self::assertMatchesRegularExpression('/:root\[data-theme="dark"\]\s*\{\s*color-scheme:\s*dark;/', $css);

        // High contrast must not paint dark-mode text with a light-mode grey.
        self::assertMatchesRegularExpression('/prefers-contrast: more\)\s*\{\s*:root\s*\{\s*--ink-2:\s*light-dark\(/', $css);

        self::assertMatchesRegularExpression('/:root\[data-theme\]\s*\{\s*color-scheme:\s*light;/', $print);

        foreach (['localStorage', 'pit38-theme', 'data-theme', 'data-theme-state', 'aria-label', 'prefers-color-scheme', 'theme-color'] as $needle) {
            self::assertStringContainsString($needle, $script);
        }
    }

    private static function assertThemeScriptPrecedesStylesheet(Crawler $crawler): void
    {
        $script = $crawler->filter('head script[src$="/js/theme.js"]');
        self::assertCount(1, $script);
        self::assertNull($script->attr('defer'), 'A deferred theme script would run after first paint.');
        self::assertNull($script->attr('async'));

        $head = (string) $crawler->filter('head')->html();
        $scriptAt = strpos($head, 'js/theme.js');
        $stylesheetAt = strpos($head, 'rel="stylesheet"');
        self::assertIsInt($scriptAt);
        self::assertIsInt($stylesheetAt);
        self::assertLessThan($stylesheetAt, $scriptAt);
    }

    private static function assetContents(string $path): string
    {
        $file = dirname(__DIR__, 2).'/public/'.$path;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /** @param array<string, string> $files filename => content */
    private function import(KernelBrowser $client, array $files): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $uploads = [];

        foreach ($files as $name => $content) {
            $path = tempnam(sys_get_temp_dir(), 'pittheme');
            self::assertIsString($path);
            file_put_contents($path, $content);
            $this->tempFiles[] = $path;
            $uploads[] = new UploadedFile($path, $name, 'text/csv', null, true);
        }

        return $client->request(
            'POST',
            '/kalkulator/import',
            ['tax_year' => '2025', '_token' => $token],
            ['files' => $uploads],
        );
    }
}
