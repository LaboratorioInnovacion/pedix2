<?php declare(strict_types=1);
namespace Tests;
use InvalidArgumentException; use TypeError; use VO\Domain\Money;

final class MoneyTest extends TestCase
{
    public function testIntegerCentsAddSubtractAndFormat(): void
    {
        $sum = Money::fromCents(1000)->add(Money::fromCents(250));
        $this->assertSame(1250, $sum->cents()); $this->assertSame(750, $sum->sub(Money::fromCents(500))->cents()); $this->assertSame('ARS 12.50', $sum->format());
        $this->assertThrows(InvalidArgumentException::class, static fn () => Money::fromCents(1, 'ARS')->add(Money::fromCents(1, 'USD')));
    }

    public function testRejectsFloatsAndRoundsPercentageHalfEven(): void
    {
        $this->assertThrows(TypeError::class, static fn () => Money::fromCents(1.25)); $this->assertSame(0, Money::fromCents(1)->pct(5000)->cents()); $this->assertSame(2, Money::fromCents(3)->pct(5000)->cents());
    }
}
