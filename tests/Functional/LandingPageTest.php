<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
        // same-origin. Plain hyperlinks to the repository are fine.
        preg_match_all('/<(script|link|img|iframe)\b[^>]*\b(?:src|href)="([^"]*)"/i', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
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
