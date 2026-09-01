<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Web\Upload\UploadedCsvReader;
use App\Web\Upload\UploadRejection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(UploadedCsvReader::class)]
final class UploadedCsvReaderTest extends TestCase
{
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
    }

    public function testReadsAcceptedFileIntoMemory(): void
    {
        $reader = $this->reader();
        $file = $this->upload("name,country\nAAA,US\n", 'trades.csv');

        $result = $reader->read([$file]);

        self::assertCount(1, $result->sources);
        self::assertSame('trades.csv', $result->sources[0]->name);
        self::assertStringContainsString('AAA,US', $result->sources[0]->content);
        self::assertSame([], $result->rejections);
    }

    public function testTemporaryUploadFileIsDeletedImmediatelyAfterReading(): void
    {
        $file = $this->upload("name,country\nAAA,US\n", 'trades.csv');
        $path = $file->getPathname();

        self::assertFileExists($path);

        $this->reader()->read([$file]);

        self::assertFileDoesNotExist($path);
    }

    public function testTooManyFilesAreRejected(): void
    {
        $reader = new UploadedCsvReader(maxFiles: 2, maxBytes: 1024);

        $result = $reader->read([
            $this->upload("a,b\n1,2\n", 'a.csv'),
            $this->upload("a,b\n1,2\n", 'b.csv'),
            $this->upload("a,b\n1,2\n", 'c.csv'),
        ]);

        self::assertCount(2, $result->sources);
        self::assertCount(1, $result->rejections);
        self::assertStringContainsString('c.csv', $result->rejections[0]->describe());
    }

    public function testOversizedFileIsRejected(): void
    {
        $reader = new UploadedCsvReader(maxFiles: 5, maxBytes: 10);

        $result = $reader->read([$this->upload(str_repeat('a,b\n', 100), 'big.csv')]);

        self::assertSame([], $result->sources);
        self::assertCount(1, $result->rejections);
        self::assertStringContainsString('big.csv', $result->rejections[0]->describe());
    }

    public function testEmptyFileIsRejected(): void
    {
        $result = $this->reader()->read([$this->upload('', 'empty.csv')]);

        self::assertSame([], $result->sources);
        self::assertCount(1, $result->rejections);
    }

    public function testDisallowedExtensionIsRejected(): void
    {
        $result = $this->reader()->read([$this->upload("name,country\nAAA,US\n", 'evil.php')]);

        self::assertSame([], $result->sources);
        self::assertCount(1, $result->rejections);
    }

    public function testBinaryContentIsRejectedEvenWithACsvExtension(): void
    {
        $result = $this->reader()->read([
            $this->upload("\x00\x01\x02PNG binary payload\x00", 'sneaky.csv'),
        ]);

        self::assertSame([], $result->sources);
        self::assertCount(1, $result->rejections);
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $result = $this->reader()->read([$this->upload("name,country\n\xC3\x28,US\n", 'broken.csv')]);

        self::assertSame([], $result->sources);
        self::assertCount(1, $result->rejections);
    }

    public function testContentWithoutAnyDelimiterIsRejected(): void
    {
        $result = $this->reader()->read([$this->upload("just prose\nwithout delimiters\n", 'prose.csv')]);

        self::assertSame([], $result->sources);
        self::assertCount(1, $result->rejections);
    }

    public function testFilenameWithPathTraversalIsReducedToItsBasename(): void
    {
        $result = $this->reader()->read([
            $this->upload("name,country\nAAA,US\n", '../../etc/passwd.csv'),
        ]);

        self::assertCount(1, $result->sources);
        self::assertSame('passwd.csv', $result->sources[0]->name);
        self::assertStringNotContainsString('..', $result->sources[0]->name);
    }

    public function testRejectionsAreSafeToShowAndDoNotLeakServerPaths(): void
    {
        $result = $this->reader()->read([$this->upload('', 'empty.csv')]);

        self::assertInstanceOf(UploadRejection::class, $result->rejections[0]);
        self::assertStringNotContainsString(sys_get_temp_dir(), $result->rejections[0]->describe());
    }

    public function testNothingIsWrittenIntoTheProjectDirectory(): void
    {
        $projectDir = dirname(__DIR__, 3);
        $before = self::snapshot($projectDir.'/var');

        $this->reader()->read([$this->upload("name,country\nAAA,US\n", 'trades.csv')]);

        self::assertSame($before, self::snapshot($projectDir.'/var'));
    }

    private function reader(): UploadedCsvReader
    {
        return new UploadedCsvReader(maxFiles: 10, maxBytes: 5_242_880);
    }

    private function upload(string $content, string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pitupload');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $clientName, 'text/csv', null, true);
    }

    /**
     * @return list<string>
     */
    private static function snapshot(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }
}
