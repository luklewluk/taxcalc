<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Asset;

use App\Web\Asset\ContentHashVersionStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A file's URL carries a hash of its content, so a changed file gets a new
 * URL - and a browser holding the old one for a year never sees new markup
 * with stale styles - while an unchanged file keeps its URL across deploys.
 */
#[CoversClass(ContentHashVersionStrategy::class)]
final class ContentHashVersionStrategyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/asset-version-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/css', 0o777, true);
        file_put_contents($this->dir.'/css/app.css', 'body { color: red; }');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/css/app.css');
        @rmdir($this->dir.'/css');
        @rmdir($this->dir);
    }

    public function testTheVersionIsAShortHashOfTheContent(): void
    {
        $strategy = new ContentHashVersionStrategy($this->dir);

        $version = $strategy->getVersion('css/app.css');

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $version);
        self::assertSame(substr(hash('xxh128', 'body { color: red; }'), 0, 12), $version);
        self::assertSame($version, (new ContentHashVersionStrategy($this->dir))->getVersion('/css/app.css'));
    }

    public function testChangedContentGetsANewVersion(): void
    {
        $before = (new ContentHashVersionStrategy($this->dir))->getVersion('css/app.css');
        file_put_contents($this->dir.'/css/app.css', 'body { color: blue; }');

        self::assertNotSame($before, (new ContentHashVersionStrategy($this->dir))->getVersion('css/app.css'));
    }

    public function testTheVersionIsAQueryParameterOnTheUnchangedPath(): void
    {
        $strategy = new ContentHashVersionStrategy($this->dir);

        self::assertSame('css/app.css?v='.$strategy->getVersion('css/app.css'), $strategy->applyVersion('css/app.css'));
    }

    public function testAMissingFileKeepsItsPathWithoutAVersion(): void
    {
        $strategy = new ContentHashVersionStrategy($this->dir);

        self::assertSame('', $strategy->getVersion('css/missing.css'));
        self::assertSame('css/missing.css', $strategy->applyVersion('css/missing.css'));
        self::assertSame('../secret', $strategy->applyVersion('../secret'), 'Nothing outside public/ is read.');
    }
}
