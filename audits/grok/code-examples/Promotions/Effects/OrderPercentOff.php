<?php

declare(strict_types=1);

namespace Commerz\Promotions\Effects;

use Commerz\Money\Data\Money;
use Commerz\Money\Enums\RoundingMode;
use Commerz\Pricing\Data\PricedLine;
use Commerz\Promotions\Contracts\EffectConfig;
use Commerz\Promotions\Contracts\PromotionEffect;
use Commerz\Promotions\Data\AdjustmentProposal;
use Commerz\Promotions\Data\PromotionInput;
use Commerz\Promotions\Exceptions\InvalidPromotionConfig;
use Override;

final readonly class OrderPercentOffConfig implements EffectConfig
{
    /** @param array<string, int> $capMinor currency => потолок скидки (необязателен) */
    public function __construct(public string $percent, public array $capMinor = []) {}

    #[Override]
    public static function fromArray(array $raw): static
    {
        $percent = $raw['percent'] ?? null;
        // строка, не float: '12.5' — точное значение
        if (! is_string($percent) || preg_match('/\A(100(\.0{1,2})?|\d{1,2}(\.\d{1,2})?)\z/D', $percent) !== 1 || (float) $percent === 0.0) {
            throw InvalidPromotionConfig::field('percent', "decimal string in (0, 100], e.g. '12.5'");
        }

        $cap = [];
        foreach ((array) ($raw['cap_minor'] ?? []) as $currency => $minor) {
            if (! is_string($currency) || ! is_int($minor) || $minor <= 0) {
                throw InvalidPromotionConfig::field("cap_minor.{$currency}", 'positive int per ISO currency');
            }
            $cap[$currency] = $minor;
        }

        return new static($percent, $cap);
    }

    #[Override]
    public function toArray(): array
    {
        return ['percent' => $this->percent, 'cap_minor' => $this->capMinor];
    }

    #[Override]
    public static function upgrade(int $fromVersion, array $raw): array
    {
        return $raw;
    }
}

/** Процент от текущего подытога; распределение по строкам — наибольший остаток (сумма частей == скидка). */
final readonly class OrderPercentOff implements PromotionEffect
{
    #[Override]
    public function key(): string
    {
        return 'order.percent_off';
    }

    #[Override]
    public function configClass(): string
    {
        return OrderPercentOffConfig::class;
    }

    #[Override]
    public function propose(EffectConfig $config, PromotionInput $input): array
    {
        assert($config instanceof OrderPercentOffConfig);

        $lines = array_values(array_filter($input->lines, static fn (PricedLine $l): bool => $l->current()->isPositive()));
        if ($lines === []) {
            return [];
        }

        $discount = $input->itemsTotal->percentage($config->percent, RoundingMode::Down);
        $cap = $config->capMinor[$input->context->currency->code] ?? null;
        if ($cap !== null) {
            $discount = $discount->min(new Money($cap, $discount->currency));
        }
        if (! $discount->isPositive()) {
            return [];
        }

        $weights = array_map(static fn (PricedLine $l): int => $l->current()->amountMinor, $lines);
        $parts = $discount->allocate($weights);

        return array_map(
            static fn (PricedLine $line, Money $part): AdjustmentProposal => new AdjustmentProposal($line->lineId, $part),
            $lines,
            $parts,
        );
    }
}
