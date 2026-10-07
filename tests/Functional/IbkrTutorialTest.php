<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Activity Statement download, shown step by step with screenshots that
 * live in public/ - nothing is loaded from IBKR or anywhere else.
 */
final class IbkrTutorialTest extends WebTestCase
{
    public function testTheIbkrHelpWalksThroughTheStatementWithThreeScreenshots(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kalkulator');

        $help = $crawler->filterXPath('//details[summary[normalize-space()="Jak pobrać pliki z IBKR"]]');
        self::assertCount(1, $help);
        self::assertNull($help->attr('open'), 'The help stays collapsed until asked for.');

        $steps = $help->filter('ol.tutorial > li.tutorial__step');
        self::assertCount(3, $steps);
        self::assertStringContainsString('Statements', $steps->eq(0)->text());
        self::assertStringContainsString('Activity Statement', $steps->eq(1)->text());
        self::assertStringContainsString('Download CSV', $steps->eq(2)->text());

        $images = $help->filter('.tutorial__shot img');
        self::assertCount(3, $images);

        $images->each(static function (Crawler $image): void {
            $src = (string) $image->attr('src');
            self::assertStringStartsWith('/img/ibkr/', $src);
            self::assertFileExists(dirname(__DIR__, 2).'/public'.$src);
            self::assertNotSame('', trim((string) $image->attr('alt')));
            self::assertMatchesRegularExpression('/^\d+$/', (string) $image->attr('width'));
            self::assertMatchesRegularExpression('/^\d+$/', (string) $image->attr('height'));
            self::assertSame('lazy', $image->attr('loading'));
        });
    }
}
