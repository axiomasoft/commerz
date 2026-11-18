<?php

declare(strict_types=1);

namespace Commerz\Money\Data;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Commerz\Attributes\Api;
use Commerz\Money\Enums\RoundingMode;
use InvalidArgumentException;

/**
 * Деньги в целых минорных единицах. Тот же формат, что `money` в legacy-platform-custom-fields: {minor:int, currency}.
 * Промежуточная арифметика — brick/math; наружу — только int.
 */
#[Api(since: '1.0')]
final readonly class Money
{
    public function __construct(public int $amountMinor, public Currency $currency) {}

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function times(int $quantity): self
    {
        return new self($this->amountMinor * $quantity, $this->currency);
    }

    /** $percent — строка ('12.5'), чтобы не тащить float в деньги. */
    public function percentage(string $percent, RoundingMode $rounding): self
    {
        $value = BigDecimal::of($this->amountMinor)
            ->multipliedBy(BigDecimal::of($percent))
            ->dividedBy(100, 0, $rounding->toBrick());

        return new self($value->toInt(), $this->currency);
    }

    public function min(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor <= $other->amountMinor ? $this : $other;
    }

    public function isPositive(): bool
    {
        return $this->amountMinor > 0;
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    /**
     * Распределение суммы пропорционально весам методом наибольшего остатка.
     * Инвариант PRM-02: сумма частей == $this. При равных остатках выигрывает меньший индекс (детерминизм).
     *
     * @param  list<int>  $weights  неотрицательные веса (обычно суммы строк в минорных единицах)
     * @return list<self>
     */
    public function allocate(array $weights): array
    {
        $total = array_sum($weights);
        if ($weights === [] || $total <= 0 || min($weights) < 0) {
            throw new InvalidArgumentException('Allocation weights must be non-empty, non-negative and sum above zero.');
        }

        $amount = BigInteger::of($this->amountMinor);
        $shares = [];
        $remainders = [];
        foreach ($weights as $index => $weight) {
            $product = $amount->multipliedBy($weight);          // без переполнения int64
            $shares[$index] = $product->quotient($total)->toInt();
            $remainders[$index] = $product->remainder($total)->toInt();
        }

        $left = $this->amountMinor - array_sum($shares);
        // по убыванию остатка, при равенстве — по индексу
        uksort($remainders, static fn (int $a, int $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
        foreach (array_keys($remainders) as $index) {
            if ($left === 0) {
                break;
            }
            $shares[$index]++;
            $left--;
        }
        ksort($shares);

        return array_map(fn (int $minor): self => new self($minor, $this->currency), array_values($shares));
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency->code} vs {$other->currency->code}.");
        }
    }
}
