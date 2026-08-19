<?php declare(strict_types=1);
namespace VO\Domain;
use InvalidArgumentException;

final class Money
{
    private function __construct(private int $cents, private string $currency) {}

    public static function fromCents(int $cents, string $currency = 'ARS'): self { return new self($cents, strtoupper($currency)); }

    public function cents(): int { return $this->cents; }
    public function currency(): string { return $this->currency; }

    public function add(self $other): self { $this->assertCurrency($other); return new self($this->cents + $other->cents, $this->currency); }
    public function sub(self $other): self { $this->assertCurrency($other); return new self($this->cents - $other->cents, $this->currency); }

    public function pct(int $basisPoints): self { return new self($this->halfEven($this->cents * $basisPoints, 10000), $this->currency); }

    public function format(): string
    {
        $sign = $this->cents < 0 ? '-' : ''; $abs = abs($this->cents);
        return $this->currency . ' ' . $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private function halfEven(int $num, int $den): int
    {
        $sign = $num < 0 ? -1 : 1; $num = abs($num); $q = intdiv($num, $den); $r = $num % $den;
        if ($r * 2 > $den || ($r * 2 === $den && $q % 2 === 1)) $q++;
        return $q * $sign;
    }

    private function assertCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) throw new InvalidArgumentException('Currency mismatch.');
    }
}
