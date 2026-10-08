<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class LandingPageTest extends WebTestCase
{
    public function testLandingPageExplainsThePrivacyModelAndIsInPolish(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('pl', $crawler->filter('html')->attr('lang'));

        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('PIT-38', $text);
        self::assertStringContainsString('open source', mb_strtolower($text));
    }

    public function testLandingPageCarriesTheTaxDisclaimer(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertStringContainsString(
            'nie stanowi porady podatkowej',
            $crawler->filter('body')->text(),
        );
    }

    public function testLandingPageLinksToTheCalculator(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertGreaterThan(0, $crawler->filter('a[href="/kalkulator"]')->count());
    }

    public function testLandingPageListsTheSupportedFormats(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        $text = $crawler->filter('body')->text();

        self::assertStringContainsString('Interactive Brokers', $text);
        self::assertStringContainsString('DEGIRO', $text);
        self::assertStringContainsString('CSV', $text);
    }

    public function testTheSupportedBrokersAreShownByTheirLogosUnderAHeading(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $section = $crawler->filter('section[aria-labelledby="brokerzy"]');
        self::assertSame('Obsługiwani brokerzy', trim($section->filter('h2#brokerzy')->text()));
        self::assertStringNotContainsString('visually-hidden', (string) $section->filter('h2#brokerzy')->attr('class'));

        // Inline, so the lettering can follow the theme; nothing is fetched.
        $logos = $section->filter('.broker-logos svg[role="img"]');
        self::assertSame(['Interactive Brokers', 'DEGIRO'], $logos->each(
            static fn (Crawler $logo): string => (string) $logo->attr('aria-label'),
        ));
        self::assertCount(0, $section->filter('img'));

        // The heading keeps the left edge every other section heading has;
        // only the logos are centred, each in its own cell.
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/public/css/app.css');
        self::assertDoesNotMatchRegularExpression('/\.brokers\s*\{[^}]*text-align:\s*center/', $css);
        self::assertMatchesRegularExpression('/\.broker-logos\s*\{[^}]*display:\s*grid/', $css);
        $logos->each(static function (Crawler $logo): void {
            self::assertStringContainsString('currentColor', $logo->html());
            self::assertStringNotContainsString('style', $logo->html());
        });
    }

    public function testTheHeroCarriesNoTaglinesUnderItsButtons(): void
    {
        $client = static::createClient();
        $text = $client->request('GET', '/')->filter('main')->text();

        self::assertStringNotContainsString('Działa z eksportami', $text);
        foreach (['Bez konta', 'Bez bazy danych', 'Bez śledzenia'] as $chip) {
            self::assertStringNotContainsString($chip, $text);
        }
    }

    public function testTheNavigationReportsProblemsInsteadOfLinkingTheCode(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $links = $crawler->filter('.site-nav a');
        self::assertSame(['Start', 'Skąd wziąć pliki', 'Zgłoś problem'], $links->each(
            static fn (Crawler $link): string => trim($link->text()),
        ));
        self::assertStringEndsWith('/issues', (string) $links->last()->attr('href'));
    }

    public function testTheHeroSaysWhatItIsAndOffersTheCalculatorAndADemo(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSame('Kalkulator PIT-38 dla inwestorów', trim($crawler->filter('.hero h1')->text()));
        $actions = $crawler->filter('.hero__actions a');
        self::assertSame(['Oblicz podatek', 'Symulacja'], $actions->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertSame(['/kalkulator', '/kalkulator/symulacja'], $actions->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertStringNotContainsString('Jak to działa', $crawler->filter('main')->text());
    }

    public function testTheFilesGuideIsInTheMenuAndMarksItselfCurrent(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/skad-wziac-pliki');

        $current = $crawler->filter('.site-nav a[aria-current="page"]');
        self::assertCount(1, $current);
        self::assertSame('Skąd wziąć pliki', trim($current->text()));
        self::assertSame('/skad-wziac-pliki', $current->attr('href'));

        $faq = $client->request('GET', '/')->filter('section[aria-labelledby="faq"]');
        self::assertGreaterThan(0, $faq->filter('a[href="/skad-wziac-pliki"]')->count());
    }

    public function testTheDetailsAreAnFaqAndTheFlowSectionIsGone(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        $text = $crawler->filter('main')->text();

        $faq = $crawler->filter('section[aria-labelledby="faq"]');
        self::assertCount(1, $faq);
        self::assertSame('FAQ', trim($faq->filter('.eyebrow')->text()));
        self::assertSame('Najczęstsze pytania', trim($faq->filter('h2#faq')->text()));
        $faq->filter('details > summary')->each(
            static fn (Crawler $question) => self::assertStringEndsWith('?', trim($question->text())),
        );

        self::assertCount(0, $crawler->filter('.flow, #jak-to-dziala, #szczegoly'));
        self::assertStringNotContainsString('Przebieg', $text);
        self::assertStringNotContainsString('Dobrze wiedzieć', $text);
        self::assertStringNotContainsString('Źródła danych', $text);
    }

    public function testNoThirdPartyResourcesAreReferenced(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('https://fonts.', $html);
        self::assertStringNotContainsString('googleapis', $html);
        self::assertStringNotContainsString('cdn.jsdelivr', $html);
        self::assertStringNotContainsString('unpkg.com', $html);
        self::assertStringNotContainsString('google-analytics', $html);

        // Every loaded sub-resource (script, stylesheet, image, frame) must be
        // same-origin. Plain hyperlinks to the repository are fine, and so is
        // the canonical link: it names the page's public address, the browser
        // fetches nothing from it.
        preg_match_all('/<(script|link|img|iframe)\b[^>]*\b(?:src|href)="([^"]*)"[^>]*>/i', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (1 === preg_match('/\brel="canonical"/i', $match[0])) {
                continue;
            }

            self::assertDoesNotMatchRegularExpression(
                '#^(https?:)?//#i',
                $match[2],
                sprintf('External sub-resource in <%s>: %s', $match[1], $match[2]),
            );
        }
    }

    public function testSecurityHeadersArePresent(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $response = $client->getResponse();

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertNotNull($response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertNotNull($response->headers->get('Permissions-Policy'));
    }

    public function testUnknownUrlReturnsAFriendly404WithoutLeakingInternals(): void
    {
        $client = static::createClient();
        $client->request('GET', '/nie-ma-takiej-strony');

        self::assertResponseStatusCodeSame(404);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Symfony\\Component', $html);
        self::assertStringNotContainsString('/root/workspace', $html);
    }
}
