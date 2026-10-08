<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Lots;

use App\Fifo\LotAllocation;
use App\Fifo\LotAssignments;
use App\Money\Decimal;
use App\Web\Lots\LotAssignmentCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The named lots ride the form in one hidden field. It is attacker-controlled
 * like every posted value, so decoding is total: anything malformed is a
 * finding, never an exception and never a silent FIFO.
 */
#[CoversClass(LotAssignmentCodec::class)]
final class LotAssignmentCodecTest extends TestCase
{
    public function testAssignmentsRoundTrip(): void
    {
        $codec = new LotAssignmentCodec();
        $assignments = new LotAssignments([
            'sale-1' => [new LotAllocation('lot-2', Decimal::of('8')), new LotAllocation('lot-1', Decimal::of('2.5'))],
            'sale-2' => [new LotAllocation('lot-3', Decimal::of('10'))],
        ]);

        $decoded = $codec->decode($codec->encode($assignments));

        self::assertTrue($decoded->valid);
        self::assertEquals($assignments, $decoded->assignments);
    }

    public function testNothingNamedIsAnEmptyField(): void
    {
        $codec = new LotAssignmentCodec();

        self::assertSame('', $codec->encode(new LotAssignments()));
        foreach (['', null] as $empty) {
            $decoded = $codec->decode($empty);
            self::assertTrue($decoded->valid);
            self::assertTrue($decoded->assignments->isEmpty());
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function tampered(): iterable
    {
        yield 'not json' => ['{oops'];
        yield 'an array instead of a string' => [['v' => 1]];
        yield 'wrong version' => ['{"v":2,"a":[]}'];
        yield 'no list' => ['{"v":1,"a":{"x":1}}'];
        yield 'exponent quantity' => ['{"v":1,"a":[["s",[["l","1e999999999"]]]]}'];
        yield 'zero quantity' => ['{"v":1,"a":[["s",[["l","0"]]]]}'];
        yield 'negative quantity' => ['{"v":1,"a":[["s",[["l","-3"]]]]}'];
        yield 'numeric quantity' => ['{"v":1,"a":[["s",[["l",3]]]]}'];
        yield 'empty id' => ['{"v":1,"a":[["",[["l","3"]]]]}'];
        yield 'control character in id' => ["{\"v\":1,\"a\":[[\"s\\u0000\",[[\"l\",\"3\"]]]]}"];
        yield 'one sale twice' => ['{"v":1,"a":[["s",[["l","3"]]],["s",[["m","3"]]]]}'];
        yield 'one lot twice in a sale' => ['{"v":1,"a":[["s",[["l","3"],["l","1"]]]]}'];
        yield 'no lots' => ['{"v":1,"a":[["s",[]]]}'];
        yield 'nesting bomb' => [str_repeat('[', 100).str_repeat(']', 100)];
    }

    #[DataProvider('tampered')]
    public function testATamperedFieldIsAFindingNotAnException(mixed $raw): void
    {
        $decoded = (new LotAssignmentCodec())->decode($raw);

        self::assertFalse($decoded->valid);
        self::assertTrue($decoded->assignments->isEmpty());
        self::assertNotSame('', (string) $decoded->message);
    }

    public function testTheCapsApply(): void
    {
        $many = [];
        for ($i = 0; $i < 4; ++$i) {
            $many[] = ['s'.$i, [['l', '1']]];
        }

        $decoded = (new LotAssignmentCodec(maxRows: 3))->decode((string) json_encode(['v' => 1, 'a' => $many]));

        self::assertFalse($decoded->valid);
    }
}
