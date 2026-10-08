<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Lots;

use App\Fifo\FifoMatcher;
use App\Fifo\LotAllocation;
use App\Fifo\LotAssignments;
use App\Fifo\Trade;
use App\Money\Amount;
use App\Money\Decimal;
use App\Web\Lots\LotAssignmentCodec;
use App\Web\Lots\LotSelection;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The lot actions are real submits: open the editor for one sale, save it,
 * return a sale to FIFO, or clear everything. The posted state is the only
 * state there is.
 */
#[CoversClass(LotSelection::class)]
final class LotSelectionTest extends TestCase
{
    private Trade $older;

    private Trade $newer;

    private Trade $first;

    private Trade $second;

    protected function setUp(): void
    {
        $this->older = self::trade('2024-01-10', '10', '1000.00');
        $this->newer = self::trade('2024-02-10', '10', '1500.00');
        $this->first = self::trade('2024-03-10', '-10', '2000.00');
        $this->second = self::trade('2024-04-10', '-10', '2200.00');
    }

    public function testNothingPostedIsFifoEverywhere(): void
    {
        $result = $this->read([]);

        self::assertTrue($result->fieldValid);
        self::assertTrue($result->assignments->isEmpty());
        self::assertSame('', $result->field);
        self::assertNull($result->editor);
    }

    public function testEditOpensTheEditorOfOneSale(): void
    {
        $result = $this->read(['lot_edit' => $this->first->id()]);

        self::assertSame($this->first->id(), $result->editor?->saleId);
        self::assertSame([], $result->editor->errors);
    }

    public function testEditingSomethingThatIsNoStockSaleIsAReviewItemNotABlock(): void
    {
        $result = $this->read(['lot_edit' => $this->older->id()]);

        self::assertNull($result->editor);
        self::assertSame([], $result->errors);
        self::assertSame('lots.edit_unavailable', $result->diagnostics[0]->code);
    }

    public function testSaveStoresTheNamedQuantities(): void
    {
        $result = $this->read($this->save($this->first, [$this->older->id() => '', $this->newer->id() => '10']));

        self::assertNull($result->editor);
        $allocations = $result->assignments->for($this->first->id());
        self::assertNotNull($allocations);
        self::assertSame($this->newer->id(), $allocations[0]->buyTradeId);
        self::assertSame('10', (string) $allocations[0]->quantity);
        self::assertNotSame('', $result->field);
    }

    public function testQuantitiesThatDoNotAddUpKeepTheEditorOpenAndTheStateUnchanged(): void
    {
        $result = $this->read($this->save($this->first, [$this->older->id() => '3', $this->newer->id() => '4']));

        self::assertTrue($result->assignments->isEmpty());
        self::assertSame($this->first->id(), $result->editor?->saleId);
        self::assertNotEmpty($result->editor->errors);
        self::assertSame('4', $result->editor->values[$this->newer->id()] ?? null);
        self::assertSame([], $result->errors, 'Nothing committed changed, so nothing blocks.');
    }

    public function testALotAlreadyUsedUpIsRefusedInTheEditor(): void
    {
        // The first sale is FIFO and empties the older lot.
        $result = $this->read($this->save($this->second, [$this->older->id() => '5', $this->newer->id() => '5']));

        self::assertTrue($result->assignments->isEmpty());
        self::assertStringContainsString('2024-01-10', implode(' ', $result->editor?->errors ?? []));
    }

    public function testATruncatedEditorIsRefused(): void
    {
        $post = $this->save($this->first, [$this->newer->id() => '10']);
        $post['lot_edit_count'] = '2';

        $result = $this->read($post);

        self::assertTrue($result->assignments->isEmpty());
        self::assertNotEmpty($result->editor?->errors);
    }

    public function testGarbageQuantityIsRefused(): void
    {
        $result = $this->read($this->save($this->first, [$this->newer->id() => 'dziesięć']));

        self::assertTrue($result->assignments->isEmpty());
        self::assertNotEmpty($result->editor?->errors);
    }

    public function testFifoRemovesOneSaleAndResetRemovesAll(): void
    {
        $stored = $this->stored();

        $fifo = $this->read(['lot_assignments' => $stored, 'lot_fifo' => $this->first->id()]);
        self::assertTrue($fifo->assignments->isEmpty());

        $reset = $this->read(['lot_assignments' => '{broken', 'lot_reset' => '1']);
        self::assertTrue($reset->fieldValid);
        self::assertTrue($reset->assignments->isEmpty());
    }

    public function testATamperedFieldBlocksAndIsEchoedBack(): void
    {
        $result = $this->read(['lot_assignments' => '{broken', 'lot_save' => $this->first->id()]);

        self::assertFalse($result->fieldValid);
        self::assertSame('{broken', $result->field);
        self::assertNotEmpty($result->errors);
        self::assertSame('lots.invalid_field', $result->diagnostics[0]->code);
    }

    public function testADeletedSaleTakesItsAssignmentAlong(): void
    {
        $trades = [$this->older, $this->newer, $this->second];

        $result = $this->read(['lot_assignments' => $this->stored()], $trades);

        self::assertTrue($result->assignments->isEmpty());
        self::assertSame([], $result->diagnostics);
    }

    public function testASaleTurnedIntoABuyDropsItsAssignmentWithAReviewItem(): void
    {
        $flipped = new Trade(
            'AAA',
            new DateTimeImmutable('2024-03-10'),
            Decimal::of('10'),
            Amount::of('2000.00', 'USD'),
            stableId: $this->first->id(),
        );

        $result = $this->read(['lot_assignments' => $this->stored()], [$this->older, $this->newer, $flipped, $this->second]);

        self::assertTrue($result->assignments->isEmpty());
        self::assertSame('lots.assignment_dropped', $result->diagnostics[0]->code);
    }

    public function testAnInvalidSaleRowKeepsItsAssignment(): void
    {
        // The row is still posted but failed validation, so it maps to no trade.
        $trades = [$this->older, $this->newer, $this->second];
        $rows = self::rows([$this->older, $this->newer, $this->first, $this->second]);

        $result = (new LotSelection(new LotAssignmentCodec(), new FifoMatcher()))
            ->read(['lot_assignments' => $this->stored()], $trades, $rows, false);

        self::assertTrue($result->assignments->has($this->first->id()));
    }

    /**
     * Only the edited sale is validated: saving the earlier sale onto a lot a
     * later manual sale already holds must succeed (the later one is then
     * reported), or swapping two sales' lots would be impossible in any order.
     */
    public function testTwoSalesCanSwapLots(): void
    {
        $trades = [$this->older, $this->newer, $this->first, $this->second];
        $selection = new LotSelection(new LotAssignmentCodec(), new FifoMatcher());

        // The second sale holds the newer lot; the first is FIFO (older lot).
        $state = (new LotAssignmentCodec())->encode(new LotAssignments([
            $this->second->id() => [new LotAllocation($this->newer->id(), Decimal::of('10'))],
        ]));

        $post = $this->save($this->first, [$this->older->id() => '', $this->newer->id() => '10']);
        $post['lot_assignments'] = $state;
        $one = $selection->read($post, $trades, self::rows($trades), true);
        self::assertNull($one->editor, 'The edited sale is valid, so the save goes through.');
        self::assertNotSame([], (new FifoMatcher())->match($trades, $one->assignments)->violations, 'The second sale is now overdrawn and reported.');

        $post = $this->save($this->second, [$this->older->id() => '10']);
        $post['lot_assignments'] = $one->field;
        $two = $selection->read($post, $trades, self::rows($trades), true);

        self::assertNull($two->editor);
        self::assertSame([], (new FifoMatcher())->match($trades, $two->assignments)->violations);
    }

    /**
     * @param array<string, mixed> $post
     * @param list<Trade>|null     $trades
     */
    private function read(array $post, ?array $trades = null): \App\Web\Lots\LotSelectionResult
    {
        $trades ??= [$this->older, $this->newer, $this->first, $this->second];

        return (new LotSelection(new LotAssignmentCodec(), new FifoMatcher()))->read($post, $trades, self::rows($trades), true);
    }

    /**
     * @param array<string, string> $quantities buy id => posted quantity
     *
     * @return array<string, mixed>
     */
    private function save(Trade $sale, array $quantities): array
    {
        return [
            'lot_save' => $sale->id(),
            'lot_edit_ids' => (string) json_encode(array_keys($quantities)),
            'lot_edit_count' => (string) count($quantities),
            'lot_edit_qty' => array_values($quantities),
        ];
    }

    private function stored(): string
    {
        return (new LotAssignmentCodec())->encode(new LotAssignments([
            $this->first->id() => [new LotAllocation($this->newer->id(), Decimal::of('10'))],
        ]));
    }

    /**
     * @param list<Trade> $trades
     *
     * @return list<array<string, string>>
     */
    private static function rows(array $trades): array
    {
        return array_map(static fn (Trade $trade): array => ['id' => $trade->id()], $trades);
    }

    private static function trade(string $date, string $quantity, string $total): Trade
    {
        return new Trade('AAA', new DateTimeImmutable($date), Decimal::of($quantity), Amount::of($total, 'USD'));
    }
}
