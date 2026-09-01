<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HSTS is a one-way commitment for a browser: once sent, the host is HTTPS-only
 * for the whole max-age. Sending it over plain HTTP - which is what local
 * development uses - would lock developers out of their own machine, and it is
 * ignored by browsers over HTTP anyway.
 */
final class SecurityHeadersTest extends WebTestCase
{
    public function testHstsIsNotSentOverPlainHttp(): void
    {
        $client = static::createClient();
        $client->request('GET', 'http://localhost/');

        self::assertResponseIsSuccessful();
        self::assertFalse(
            $client->getResponse()->headers->has('Strict-Transport-Security'),
            'HSTS must never be sent over HTTP',
        );
    }

    public function testHstsIsSentOverHttps(): void
    {
        $client = static::createClient();
        $client->request('GET', 'https://localhost/', server: ['HTTPS' => 'on']);

        self::assertResponseIsSuccessful();

        $hsts = (string) $client->getResponse()->headers->get('Strict-Transport-Security');
        self::assertNotSame('', $hsts);
        self::assertStringContainsString('max-age=', $hsts);
        self::assertStringContainsString('includeSubDomains', $hsts);
    }

    public function testHstsMaxAgeIsAtLeastSixMonths(): void
    {
        $client = static::createClient();
        $client->request('GET', 'https://localhost/', server: ['HTTPS' => 'on']);

        $hsts = (string) $client->getResponse()->headers->get('Strict-Transport-Security');
        self::assertSame(1, preg_match('/max-age=(\d+)/', $hsts, $m));
        self::assertGreaterThanOrEqual(15552000, (int) $m[1]);
    }

    public function testTheOtherHeadersAreSentRegardlessOfScheme(): void
    {
        $client = static::createClient();

        foreach ([[], ['HTTPS' => 'on']] as $server) {
            $client->request('GET', [] === $server ? 'http://localhost/' : 'https://localhost/', server: $server);
            $headers = $client->getResponse()->headers;

            self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
            self::assertSame('DENY', $headers->get('X-Frame-Options'));
            self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
            self::assertStringContainsString("default-src 'self'", (string) $headers->get('Content-Security-Policy'));
            self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        }
    }

    public function testHstsIsAlsoSentOnAPostResponse(): void
    {
        $client = static::createClient();
        $client->request('POST', 'https://localhost/kalkulator/wynik', server: ['HTTPS' => 'on']);

        // 403 without a CSRF token, but the header policy still applies.
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($client->getResponse()->headers->has('Strict-Transport-Security'));
    }
}
