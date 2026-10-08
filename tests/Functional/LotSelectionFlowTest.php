<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Specific lot identification in the FIFO tab: FIFO by default, a sale may
 * name the lots it closed instead. The choice rides one hidden field, every
 * action is a real submit, and a broken choice blocks rather than falling
 * back to FIFO.
 */
final class LotSelectionFlowTest extends WebTestCase
{
    /**
     * DEGIRO, listed on NASDAQ (so the country is proposed): two lots of two
     * shares, at 100 and at 150 USD, and one sale of two at 200 USD. With the
     * test rate USD 4.0 the przychód is 1 600 zł; FIFO costs 800 zł, the second
     * lot 1 200 zł.
     */
    private const string TWO_LOTS = <<<'CSV'
        Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
        02-01-2024,10:00,ALFA CORP,US000ALFA001,NDQ,XNAS,2,100.0000,USD,-200.00,USD,-200.00,USD,,,,-200.00,USD,t-6001
        01-03-2024,10:00,ALFA CORP,US000ALFA001,NDQ,XNAS,2,150.0000,USD,-300.00,USD,-300.00,USD,,,,-300.00,USD,t-6002
        03-03-2025,12:00,ALFA CORP,US000ALFA001,NDQ,XNAS,-2,200.0000,USD,400.00,USD,400.00,USD,,,,400.00,USD,t-6003
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

    public function testTheBoardListsEveryLotOutsideTheFragments(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        self::assertSame('800,00', $this->cost($crawler));
        $board = $crawler->filter('#panel-fifo [data-lot-board]');
        self::assertSame(1, $board->count());
        self::assertSame(0, $crawler->filter('[data-fragment] [data-lot-board]')->count());
        self::assertStringNotContainsString('0112-KDIL2', $board->text(), 'The legal basis lives in the explanations, not in the intro.');

        [$first, $second, $sale] = $this->ids($crawler);
        self::assertSame(1, $board->filter('#lot-open-'.$first)->count());
        self::assertStringContainsString('→ Z1', $board->filter('#lot-open-'.$first)->text());
        // Never closed, still listed.
        self::assertStringContainsString('nieprzypisana · w portfelu 2 szt.', $board->filter('#lot-open-'.$second)->text());
        self::assertStringContainsString('← P1', $board->filter('#lot-close-'.$sale)->text());
        self::assertSame('FIFO', trim($board->filter('#lot-close-'.$sale.' .lot-method')->text()));

        $edit = $board->filter('#lot-close-'.$sale.' button[name="lot_edit"]');
        self::assertSame($sale, $edit->attr('value'));
        self::assertNotNull($edit->attr('formnovalidate'));
        self::assertStringEndsWith('/kalkulator/wynik#lot-close-'.$sale, (string) $edit->attr('formaction'));
        self::assertSame(0, $crawler->filter('[style]')->count());
    }

    public function testTheChoiceIsOneHiddenFieldAheadOfTheTrades(): void
    {
        $client = static::createClient();
        $this->import($client);
        $html = (string) $client->getResponse()->getContent();

        $field = strpos($html, 'name="lot_assignments"');
        self::assertIsInt($field);
        self::assertLessThan(strpos($html, 'name="trades['), $field);
        self::assertSame(0, preg_match('/name="lot_[a-z_]+\[/', $html), 'No per-row lot field: max_input_vars.');
    }

    public function testNamingTheSecondLotChangesTheCostAndSurvivesARoundTrip(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);
        [, $second, $sale] = $this->ids($crawler);

        $crawler = $this->openEditor($client, $crawler, $sale);
        $crawler = $this->saveLots($client, $crawler, ['0', '2']);

        self::assertSame('1 200,00', $this->cost($crawler));
        self::assertSame('wskazane', trim($crawler->filter('#lot-close-'.$sale.' .lot-method')->text()));
        self::assertStringContainsString('← P2', $crawler->filter('#lot-close-'.$sale)->text());
        self::assertSame(0, $crawler->filter('[data-lot-editor]')->count());
        self::assertStringContainsString('wskazana partia', $crawler->filter('#panel-fifo [data-fragment="fifo"]')->text());
        self::assertStringContainsString('Partia wskazana ręcznie', $crawler->filter('#row-'.$sale)->text());
        self::assertSame(1, $crawler->filter('#row-'.$sale.' a[href="#lot-close-'.$sale.'"]')->count());
        self::assertStringContainsString($second, (string) $crawler->filter('input[name="lot_assignments"]')->attr('value'));

        // Posted back as is, the choice holds - full render and AJAX alike.
        $payload = $this->payload($crawler);
        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        self::assertSame('1 200,00', $this->cost($crawler));

        $client->request('POST', '/kalkulator/wynik', $payload, [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $json = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($json['ok']);
        self::assertStringContainsString('1 200,00', str_replace("\u{a0}", ' ', $json['fragments']['summary']));

        // "Przywróć FIFO" puts the oldest lot back.
        $crawler = $client->submit($crawler->filter('#lot-close-'.$sale.' button[name="lot_fifo"]')->form());
        self::assertSame('800,00', $this->cost($crawler));
        self::assertSame('', $crawler->filter('input[name="lot_assignments"]')->attr('value'));
    }

    public function testAWrongTotalKeepsTheEditorOpenAndChangesNothing(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);
        [, , $sale] = $this->ids($crawler);

        $crawler = $this->openEditor($client, $crawler, $sale);
        $crawler = $this->saveLots($client, $crawler, ['1', '0']);

        self::assertSame('800,00', $this->cost($crawler));
        self::assertSame(1, $crawler->filter('#lot-close-'.$sale.' [data-lot-editor]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-lot-editor] [role="alert"] li')->count());
        self::assertSame('', $crawler->filter('input[name="lot_assignments"]')->attr('value'));
    }

    public function testRemovingANamedLotBlocksInsteadOfFallingBackToFifo(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);
        [, $second, $sale] = $this->ids($crawler);
        $crawler = $this->saveLots($client, $this->openEditor($client, $crawler, $sale), ['0', '2']);

        $payload = $this->payload($crawler);
        foreach ($payload['trades'] as $index => $trade) {
            if ($trade['id'] === $second) {
                $payload['trades'][$index]['remove'] = '1';
            }
        }
        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('#pit-akcje')->count(), 'A broken lot choice must not produce a figure.');
        $link = $crawler->filter('#panel-attention a[href="#lot-close-'.$sale.'"]');
        self::assertSame(1, $link->count());
        self::assertSame('fifo', $link->attr('data-attention-target'));
        self::assertNull($link->attr('data-row-target'));
        self::assertStringContainsString('przywróć FIFO', $crawler->filter('#panel-attention')->text());
    }

    public function testATamperedFieldBlocksAndResetClearsIt(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);
        $payload = $this->payload($crawler);
        $payload['lot_assignments'] = '{"v":1,"a":[["x",[["y","1e999999999"]]]]}';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('#pit-akcje')->count());
        $reset = $crawler->filter('[data-lot-board] button[name="lot_reset"]');
        self::assertSame(1, $reset->count());

        $crawler = $client->submit($reset->form());
        self::assertSame('800,00', $this->cost($crawler));
        self::assertSame('', $crawler->filter('input[name="lot_assignments"]')->attr('value'));
    }

    public function testOptionsAreListedButNeverTakeALotChoice(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, (string) file_get_contents(dirname(__DIR__, 2).'/examples/ibkr-activity-statement.csv'));

        $options = $crawler->filter('[data-lot-board] .lot-queue')->reduce(
            static fn (Crawler $queue): bool => str_contains($queue->filter('summary')->text(), 'opcje'),
        );
        self::assertGreaterThan(0, $options->count());
        self::assertGreaterThan(0, $options->filter('.lot--close')->count());
        self::assertSame(0, $options->filter('button[name="lot_edit"], .lot-method')->count());
    }

    public function testTheCsvAndPrintNameTheMethodAndTheChoice(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);
        [, , $sale] = $this->ids($crawler);
        $crawler = $this->saveLots($client, $this->openEditor($client, $crawler, $sale), ['0', '2']);

        $client->request('POST', '/kalkulator/raport.csv', $this->payload($crawler));
        self::assertResponseIsSuccessful();
        $csv = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Metoda doboru partii', $csv);
        self::assertStringContainsString('wskazanie partii', $csv);
        self::assertStringContainsString('0112-KDIL2-1.4011.929.2025.1.TR', $csv);
        self::assertStringContainsString('AKTUALNY STAN - WSKAZANE PARTIE', $csv);

        $print = $client->request('POST', '/kalkulator/raport', $this->payload($crawler));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('wskazana partia', $print->text());
        self::assertStringContainsString('0112-KDIL2-1.4011.929.2025.1.TR', $print->text());
    }

    private function openEditor(KernelBrowser $client, Crawler $crawler, string $sale): Crawler
    {
        $crawler = $client->submit($crawler->filter('#lot-close-'.$sale.' button[name="lot_edit"]')->form());
        self::assertResponseIsSuccessful();
        $editor = $crawler->filter('#lot-close-'.$sale.' [data-lot-editor]');
        self::assertSame(1, $editor->count());
        self::assertSame(2, $editor->filter('input[name^="lot_edit_qty"]')->count());
        self::assertStringEndsWith('/kalkulator/wynik#lot-close-'.$sale, (string) $editor->filter('[data-lot-save]')->attr('formaction'));

        return $crawler;
    }

    /** @param list<string> $quantities one per lot, in the editor's order */
    private function saveLots(KernelBrowser $client, Crawler $crawler, array $quantities): Crawler
    {
        $form = $crawler->filter('[data-lot-save]')->form();
        foreach ($quantities as $index => $quantity) {
            $form['lot_edit_qty['.$index.']'] = $quantity;
        }
        $crawler = $client->submit($form);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return array{string, string, string} first lot, second lot, the sale */
    private function ids(Crawler $crawler): array
    {
        $ids = [];
        foreach ($this->payload($crawler)['trades'] as $trade) {
            $ids[$trade['date']] = $trade['id'];
        }

        return [$ids['2024-01-02'], $ids['2024-03-01'], $ids['2025-03-03']];
    }

    private function cost(Crawler $crawler): string
    {
        $cost = $crawler->filter('#pit-akcje + .pit-fields > div')->eq(1)->filter('dd');
        self::assertSame(1, $cost->count(), 'No PIT-38 figures on the page.');

        return trim(str_replace([' zł', "\u{a0}"], ['', ' '], $cost->text()));
    }

    private function import(KernelBrowser $client, string $content = self::TWO_LOTS): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $path = tempnam(sys_get_temp_dir(), 'pitlots');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        $crawler = $client->request(
            'POST',
            '/kalkulator/import',
            ['tax_year' => '2025', '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value')],
            ['files' => [new UploadedFile($path, 'transakcje.csv', 'text/csv', null, true)]],
        );
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return array<string, mixed> */
    private function payload(Crawler $crawler): array
    {
        return $crawler->filter('form[data-workbench]')->form()->getPhpValues();
    }
}
