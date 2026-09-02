<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExampleFileTest extends WebTestCase
{
    #[DataProvider('sampleFiles')]
    public function testSampleFilesAreServed(string $filename): void
    {
        $client = static::createClient();
        $client->request('GET', '/przyklady/'.$filename);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');

        $body = (string) $client->getResponse()->getContent();
        self::assertNotSame('', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sampleFiles(): iterable
    {
        yield 'degiro transactions' => ['degiro-transakcje.csv'];
        yield 'degiro account statement' => ['degiro-rachunek.csv'];
    }

    #[DataProvider('maliciousNames')]
    public function testArbitraryPathsAreRefused(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', '/przyklady/'.$path);

        self::assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('APP_SECRET', (string) $client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function maliciousNames(): iterable
    {
        yield 'traversal' => ['..%2F..%2F.env'];
        yield 'encoded traversal' => ['%2e%2e%2f%2e%2e%2f.env'];
        yield 'absolute' => ['%2Fetc%2Fpasswd'];
        yield 'unknown file' => ['nie-istnieje.csv'];
        yield 'php file' => ['index.php'];
    }

    public function testSampleFilesContainNoPersonalIdentifiers(): void
    {
        $client = static::createClient();

        foreach (self::sampleFiles() as [$filename]) {
            $client->request('GET', '/przyklady/'.$filename);
            $body = (string) $client->getResponse()->getContent();

            self::assertDoesNotMatchRegularExpression('/\bU\d{7,}\b/', $body, $filename.' contains an account number');
        }
    }

    public function testPublicDegiroSamplesContainNeitherUuidsNorLegacyPrivateFilename(): void
    {
        $client = static::createClient();

        foreach (['degiro-transakcje.csv', 'degiro-rachunek.csv'] as $filename) {
            $client->request('GET', '/przyklady/'.$filename);
            $body = (string) $client->getResponse()->getContent();

            self::assertDoesNotMatchRegularExpression(
                '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i',
                $body,
                $filename.' contains a UUID',
            );
        }

        $client->request('GET', '/przyklady/degiro-transactions.csv');
        self::assertResponseStatusCodeSame(404);
    }
}
