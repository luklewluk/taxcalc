<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Guards the shape of the four screens rather than their wording.
 *
 * Every one of these assertions protects a decision that is easy to undo by
 * accident: that reference material stays collapsed, that the flow says where
 * the user is, that technical columns are hidden but still submitted, and that
 * the printable report never hides anything.
 */
final class UiSurfacesTest extends WebTestCase
{
    private const string DIVIDENDS = <<<'CSV'
        name,country,currency,date,amount,tax_paid
        AAA,US,USD,2025-04-02,100.00,30.00
        BBB,IE,USD,2025-07-02,50.00,0
        CSV;

    private const string IBKR_TRADES = <<<'CSV'
        "AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"
        "STK","CSPX","20240403","3","507.5782","-1523.98","1001","USD"
        "STK","CSPX","20250227","-3","560.00","1680.00","1002","USD"
        CSV;

    /**
     * @var list<string>
     */
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

    /* Landing ------------------------------------------------------------- */

    public function testLandingOffersOneNamedCallToActionAndTheTrustLine(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        $text = $crawler->filter('body')->text();

        self::assertGreaterThan(
            0,
            $crawler->filter('a.button--primary[href="/kalkulator"]')->count(),
            'the landing page must lead with a primary action',
        );
        self::assertStringContainsString('Oblicz podatek', $text);
        self::assertStringContainsString('Bez konta', $text);
        self::assertStringContainsString('Bez bazy danych', $text);
        self::assertStringContainsString('Bez śledzenia', $text);
        self::assertStringContainsString('Działa z IBKR, DEGIRO i własnym CSV', $text);
    }

    public function testLandingNamesTheThreeStepsOfTheFlow(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        $steps = $crawler->filter('.flow .flow__title');

        self::assertSame(3, $steps->count());
        self::assertSame(['Wgraj', 'Sprawdź', 'Pobierz wynik'], $steps->each(
            static fn (Crawler $node): string => $node->text(),
        ));
    }

    public function testLandingKeepsReferenceTablesOutOfTheDefaultView(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSame(
            0,
            $crawler->filterXPath('//table[not(ancestor::details)]')->count(),
            'reference tables belong in collapsed help, not in the default view',
        );

        // The raw column reference is documentation, not a landing-page asset.
        $text = $crawler->filter('body')->text();
        self::assertStringNotContainsString('buy_total_amount', $text);
        self::assertStringNotContainsString('CurrencyPrimary', $text);
    }

    public function testLandingHidesPrivacyAndMethodBehindClosedDisclosures(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $summaries = $crawler->filter('details > summary')->each(
            static fn (Crawler $node): string => $node->text(),
        );

        self::assertContains('Co się dzieje z moimi danymi', $summaries);
        self::assertContains('Jak liczy kalkulator', $summaries);
        self::assertSame([], self::openDisclosures($crawler));
    }

    /* Upload -------------------------------------------------------------- */

    public function testUploadPageShowsTheFlowWithTheFirstStepActive(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kalkulator');

        self::assertSame(['Wgraj', 'Sprawdź', 'Wynik'], self::stepLabels($crawler));
        self::assertSame('Wgraj', self::currentStep($crawler));
    }

    public function testBrokerHelpAndSamplesStayCollapsedUntilAskedFor(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kalkulator');

        $summaries = $crawler->filter('details > summary')->each(
            static fn (Crawler $node): string => $node->text(),
        );

        self::assertContains('Jak pobrać pliki z IBKR', $summaries);
        self::assertContains('Jak pobrać pliki z DEGIRO', $summaries);
        self::assertContains('Przykładowe pliki', $summaries);
        self::assertSame([], self::openDisclosures($crawler));

        // The sample downloads live inside that collapsed section, not loose on the page.
        self::assertSame(
            0,
            $crawler->filterXPath('//a[starts-with(@href, "/przyklady/")][not(ancestor::details)]')->count(),
        );
        self::assertGreaterThanOrEqual(
            3,
            $crawler->filterXPath('//details//a[starts-with(@href, "/przyklady/")]')->count(),
        );
    }

    public function testUploadPageKeepsTheConstraintsAndTheOneObviousAction(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kalkulator');
        $text = $crawler->filter('body')->text();

        self::assertMatchesRegularExpression('/Do 10 plików CSV, każdy do 5 MB, w kodowaniu UTF-8/u', $text);
        self::assertSame(1, $crawler->filter('form .button--primary')->count());
        // Progressive-enhancement hooks for the file summary must be present.
        self::assertSame(1, $crawler->filter('[data-role="dropzone"] input[type="file"]')->count());
        self::assertSame(1, $crawler->filter('[data-role="file-summary"]')->count());
    }

    /* Review -------------------------------------------------------------- */

    public function testReviewPageMarksTheSecondStepAndSummarisesTheImport(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);

        self::assertSame('Sprawdź', self::currentStep($crawler));

        $summary = $crawler->filter('.review-bar')->text();
        self::assertStringContainsString('2', $summary);
        self::assertStringContainsString('dywidend', $summary);
        self::assertSame(1, $crawler->filter('.review-bar select[name="tax_year"]')->count());
    }

    public function testReviewHidesTechnicalMetadataBehindAProgressiveControl(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['trades.csv' => self::IBKR_TRADES]);

        // A real checkbox and label, so the columns can be revealed without JavaScript.
        self::assertSame(1, $crawler->filter('input.tech-toggle__input#show_tech')->count());
        self::assertSame(1, $crawler->filter('label[for="show_tech"]')->count());
        self::assertSame('', (string) $crawler->filter('input#show_tech')->attr('name'));

        // Quantity and source are marked as technical, yet remain in the form.
        self::assertGreaterThan(0, $crawler->filter('td.cell--tech')->count());
        self::assertSame(
            1,
            $crawler->filter('td.cell--tech input[name="positions[0][quantity]"]')->count(),
        );
        self::assertSame(
            1,
            $crawler->filter('td.cell--tech input[name="positions[0][source]"]')->count(),
        );
    }

    public function testTheEssentialColumnsAreTheOnlyVisibleOnes(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['trades.csv' => self::IBKR_TRADES]);

        $headers = $crawler->filter('.table--editable')->first()->filter('thead th')->each(
            static fn (Crawler $node): string => $node->text().('' !== (string) $node->attr('class') ? ' [tech]' : ''),
        );

        self::assertSame(
            ['Instrument', 'Kraj', 'Zakup', 'Sprzedaż', 'Liczba [tech]', 'Źródło [tech]', 'Usuń'],
            $headers,
        );
    }

    public function testTheReviewFormStillCarriesEveryFieldOfEveryRow(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, [
            'trades.csv' => self::IBKR_TRADES,
            'dividends.csv' => self::DIVIDENDS,
        ]);

        $values = $crawler->filter('form[data-role="review"]')->form()->getPhpValues();

        self::assertIsArray($values['positions']);
        self::assertIsArray($values['dividends']);

        foreach ($values['positions'] as $row) {
            self::assertIsArray($row);
            self::assertSame(
                ['name', 'currency', 'country', 'buy_date', 'buy_amount', 'sell_date', 'sell_amount', 'quantity', 'source'],
                array_keys($row),
            );
        }

        foreach ($values['dividends'] as $row) {
            self::assertIsArray($row);
            self::assertSame(
                ['name', 'currency', 'country', 'date', 'gross', 'tax_paid', 'source'],
                array_keys($row),
            );
        }
    }

    public function testTheReviewActionsRankTheOneThatMatters(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);
        $bar = $crawler->filter('.action-bar');

        self::assertSame(1, $bar->count());
        self::assertSame('Oblicz podatek', $bar->filter('.button--primary')->text());
        self::assertSame('Pobierz CSV', $bar->filter('.button--quiet')->text());
        self::assertSame('Wgraj inne pliki', $bar->filter('a.button--plain')->text());
    }

    /* Result and print ---------------------------------------------------- */

    public function testResultPageMarksTheThirdStepAndLeadsWithTheAmount(): void
    {
        $client = static::createClient();
        $crawler = $this->calculate($client);

        self::assertSame('Wynik', self::currentStep($crawler));
        self::assertStringContainsString('Twój wynik za 2025', $crawler->filter('h1')->text());

        // Both readings, side by side, above everything explanatory.
        $verdict = $crawler->filter('.verdict');
        self::assertSame(1, $verdict->count());
        self::assertStringContainsString('KIS', $verdict->text());
        self::assertStringContainsString('NSA', $verdict->text());
        self::assertSame(2, $verdict->filter('.verdict__amount')->count());

        // Four figures, not the whole tax mechanism.
        self::assertSame(4, $crawler->filter('.figures-grid .figure')->count());
    }

    public function testResultPagePutsEveryDetailedTableInAClosedDisclosure(): void
    {
        $client = static::createClient();
        $crawler = $this->calculate($client);

        self::assertSame(
            0,
            $crawler->filterXPath('//table[not(ancestor::details)]')->count(),
            'detailed tables must sit inside a disclosure',
        );
        self::assertSame([], self::openDisclosures($crawler));

        $summaries = $crawler->filter('details > summary')->each(
            static fn (Crawler $node): string => $node->text(),
        );

        self::assertContains('Dlaczego są dwa warianty?', $summaries);
        self::assertContains('Rozbicie na kraje (PIT/ZG)', $summaries);
        self::assertContains('Jak to policzyliśmy', $summaries);
    }

    public function testDownloadsSitDirectlyUnderTheSummary(): void
    {
        $client = static::createClient();
        $crawler = $this->calculate($client);

        self::assertSame(
            0,
            $crawler->filterXPath('//form[.//button[@formaction]][ancestor::details]')->count(),
            'the download form must not be hidden behind a disclosure',
        );
        self::assertGreaterThan(0, $crawler->filter('.download-actions .button--primary')->count());
    }

    /**
     * Nothing but a script can open the browser's print dialog. The page
     * therefore ships the button hidden and states the shortcut instead, and the
     * script swaps the two - so whichever way the page is loaded, the user never
     * meets a visible control that does nothing.
     */
    public function testThePrintButtonIsRevealedByScriptAndReplacedByAShortcutWithoutIt(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);
        $crawler = $client->request('POST', '/kalkulator/raport', $this->payload($crawler));

        $button = $crawler->filter('button[data-action="print"]');
        self::assertSame(1, $button->count());
        self::assertNotNull(
            $button->getNode(0)?->attributes->getNamedItem('hidden'),
            'the print button must ship hidden, because only JavaScript can make it work',
        );

        $fallback = $crawler->filter('[data-role="print-fallback"]');
        self::assertSame(1, $fallback->count(), 'without the button there has to be an instruction');
        self::assertNull(
            $fallback->getNode(0)?->attributes->getNamedItem('hidden'),
            'the instruction is the no-JavaScript state and must be visible as delivered',
        );

        $text = $fallback->text();
        self::assertMatchesRegularExpression('/Ctrl/u', $text);
        self::assertMatchesRegularExpression('/⌘/u', $text);
        self::assertMatchesRegularExpression('/przegląda/iu', $text);
    }

    public function testTheScriptLooksForExactlyTheHooksThePrintPageShips(): void
    {
        $script = self::assetContents('js/app.js');

        // Renaming one side of this contract would silently restore the dead button.
        self::assertStringContainsString('[data-action="print"][hidden]', $script);
        self::assertStringContainsString('[data-role="print-fallback"]', $script);

        // A `hidden` attribute must outrank the flex layout of `.button`.
        self::assertMatchesRegularExpression(
            '/\[hidden\]\s*\{\s*display:\s*none\s*!important;\s*\}/',
            self::assetContents('css/app.css'),
        );
    }

    /* Touch targets ------------------------------------------------------- */

    /**
     * The review screen is the one surface a phone user has to work in, and its
     * controls are deliberately compact. The 44x44 minimum therefore lives in the
     * narrow-screen block only: applying it to the desktop table would push a
     * single row past the fold.
     */
    public function testNarrowScreensGiveEveryReviewControlAFullTouchTarget(): void
    {
        $css = self::assetContents('css/app.css');

        self::assertMatchesRegularExpression('/--tap:\s*44px;/', $css, 'the token has to be the real minimum');

        $mobile = self::cssBlock($css, '@media (max-width: 47.99rem)');

        foreach ([
            '.table--editable input[type="text"]' => 'the compact editable fields',
            '.remove-toggle' => 'the delete toggle',
            '.tech-toggle__label' => 'the technical-details switch',
        ] as $selector => $what) {
            self::assertMatchesRegularExpression(
                '/'.preg_quote($selector, '/').'[^{]*\{[^}]*min-height:\s*var\(--tap\)/s',
                $mobile,
                $what.' must reach the minimum touch target on a phone',
            );
        }

        // A checkbox is square, so its target needs the width as well.
        self::assertMatchesRegularExpression(
            '/\.remove-toggle\s*\{[^}]*min-width:\s*var\(--tap\)/s',
            $mobile,
        );

        // Desktop density is the other half of the decision. Buttons and
        // disclosure summaries are 44px everywhere; these three are not.
        self::assertDoesNotMatchRegularExpression(
            '/\.(table--editable|remove-toggle|tech-toggle__label)[^{}]*\{[^}]*var\(--tap\)/s',
            str_replace($mobile, '', $css),
            'growing these controls outside the narrow-screen block would cost desktop density',
        );
    }

    public function testTheDeleteCheckboxSitsInsideItsOwnLabel(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, [
            'trades.csv' => self::IBKR_TRADES,
            'dividends.csv' => self::DIVIDENDS,
        ]);

        foreach (['positions[0][remove]', 'dividends[0][remove]'] as $name) {
            $checkbox = $crawler->filter('label.remove-toggle input[name="'.$name.'"]');
            self::assertSame(1, $checkbox->count(), $name.' must be wrapped by its own label');
        }

        // The label carries the name, so the row is identified without an aria-label.
        self::assertStringContainsString(
            'Usuń pozycję 1',
            $crawler->filter('label.remove-toggle')->first()->text(),
        );
    }

    public function testThePrintableReportOpensEveryDisclosure(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);
        $crawler = $client->request('POST', '/kalkulator/raport', $this->payload($crawler));

        self::assertResponseIsSuccessful();

        $all = $crawler->filter('details')->count();
        self::assertGreaterThan(0, $all);
        self::assertCount(
            $all,
            self::openDisclosures($crawler),
            'nothing may be collapsed on a printed report',
        );
    }

    /* Helpers ------------------------------------------------------------- */

    /**
     * @return list<string>
     */
    private static function stepLabels(Crawler $crawler): array
    {
        return $crawler->filter('.progress__step .progress__label')->each(
            static fn (Crawler $node): string => $node->text(),
        );
    }

    private static function currentStep(Crawler $crawler): string
    {
        $current = $crawler->filter('.progress__step[aria-current="step"]');
        self::assertSame(1, $current->count(), 'exactly one step is the current one');

        return $current->filter('.progress__label')->text();
    }

    private static function assetContents(string $path): string
    {
        $file = dirname(__DIR__, 2).'/public/'.$path;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /**
     * The body of a CSS block, braces balanced, so a rule can be asserted to sit
     * inside one media query and outside every other.
     */
    private static function cssBlock(string $css, string $header): string
    {
        $start = strpos($css, $header.' {');
        self::assertIsInt($start, $header.' must exist in the stylesheet');

        $open = $start + strlen($header) + 2;
        $depth = 1;
        for ($i = $open; $i < strlen($css); ++$i) {
            $depth += match ($css[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if (0 === $depth) {
                return substr($css, $open, $i - $open);
            }
        }

        self::fail($header.' is not closed');
    }

    /**
     * @return list<string>
     */
    private static function openDisclosures(Crawler $crawler): array
    {
        $open = [];
        foreach ($crawler->filter('details') as $node) {
            if ($node->hasAttribute('open')) {
                $open[] = trim($node->textContent);
            }
        }

        return $open;
    }

    /**
     * @param array<string, string> $files filename => content
     */
    private function import(KernelBrowser $client, array $files, string $year = '2025'): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $uploads = [];
        foreach ($files as $name => $content) {
            $path = tempnam(sys_get_temp_dir(), 'pitui');
            self::assertIsString($path);
            file_put_contents($path, $content);
            $this->tempFiles[] = $path;

            $uploads[] = new UploadedFile($path, $name, 'text/csv', null, true);
        }

        return $client->request(
            'POST',
            '/kalkulator/import',
            ['tax_year' => $year, '_token' => $token],
            ['files' => $uploads],
        );
    }

    private function calculate(KernelBrowser $client): Crawler
    {
        $crawler = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);

        return $client->request('POST', '/kalkulator/wynik', $this->payload($crawler));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Crawler $crawler): array
    {
        return $crawler->filter('form[data-role="review"]')->form()->getPhpValues();
    }
}
