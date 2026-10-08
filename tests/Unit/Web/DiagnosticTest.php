<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Fifo\FifoViolationKind;
use App\Fifo\UnmatchedSell;
use App\Web\Diagnostic;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Diagnostic::class)]
final class DiagnosticTest extends TestCase
{
    public function testWhatFifoCouldNotMatchOpensTheRowDetailsNotItsEditor(): void
    {
        self::assertTrue(Diagnostic::review(UnmatchedSell::CODE, 'm', 'transactions', 'id')->opensDetails());
        self::assertTrue(Diagnostic::review('fifo.'.FifoViolationKind::UnmatchedClose->value, 'm', 'transactions', 'id')->opensDetails());
        self::assertFalse(Diagnostic::blocking('trade.invalid', 'm', 'transactions', 'id')->opensDetails());
        self::assertFalse(Diagnostic::blocking('country.missing_instrument', 'm', 'transactions', 'id')->opensDetails());
    }
}
