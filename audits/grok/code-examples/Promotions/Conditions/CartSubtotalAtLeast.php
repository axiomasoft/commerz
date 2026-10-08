<?php

declare(strict_types=1);

namespace Commerz\Promotions\Conditions;

use Commerz\Promotions\Contracts\ConditionConfig;
use Commerz\Promotions\Contracts\PromotionCondition;
use Commerz\Promotions\Data\ConditionResult;
use Commerz\Promotions\Data\PromotionInput;
use Commerz\Promotions\Exceptions\InvalidPromotionConfig;
use Override;

final readonly class CartSubtotalAtLeastConfig implements ConditionConfig
{
    public const int VERSION = 2;

    /** @param array<string, int> $minimumMinor currency => минимальная сумма (мультивалютность без конвертации) */
    public function __construct(public array $minimumMinor) {}

    #[Override]
    public static function fromArray(array $raw): static
    {
        $map = $raw['minimum_minor'] ?? null;
        if (! is_array($map) || $map === []) {
            throw InvalidPromotionConfig::field('minimum_minor', 'non-empty map currency => int');
        }
        foreach ($map as $currency => $minor) {
            if (! is_string($currency) || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1 || ! is_int($minor) || $minor < 0) {
                throw InvalidPromotionConfig::field("minimum_minor.{$currency}", 'non-negative int per ISO currency');
            }
        }

        return new static($map);
    }

    #[Override]
    public function toArray(): array
    {
        return ['minimum_minor' => $this->minimumMinor];
    }

    /** v1 хранил одну сумму {amount_minor, currency}; v2 — карту по валютам. */
    #[Override]
    public static function upgrade(int $fromVersion, array $raw): array
    {
        return match ($fromVersion) {
            1 => is_string($raw['currency'] ?? null)
                ? ['minimum_minor' => [$raw['currency'] => $raw['amount_minor'] ?? null]]
                : throw InvalidPromotionConfig::field('currency', 'ISO currency in v1 config'),
            2 => $raw,
            default => throw InvalidPromotionConfig::field('config_version', "unknown version {$fromVersion}"),
        };
    }
}

final readonly class CartSubtotalAtLeast implements PromotionCondition
{
    #[Override]
    public function key(): string
    {
        return 'cart.subtotal_at_least';
    }

    #[Override]
    public function configClass(): string
    {
        return CartSubtotalAtLeastConfig::class;
    }

    #[Override]
    public function evaluate(ConditionConfig $config, PromotionInput $input): ConditionResult
    {
        assert($config instanceof CartSubtotalAtLeastConfig);

        $currency = $input->context->currency->code;
        $minimum = $config->minimumMinor[$currency] ?? null;

        return match (true) {
            $minimum === null => ConditionResult::failed("no_threshold_for_{$currency}"),
            $input->itemsTotal->amountMinor >= $minimum => ConditionResult::matched(),
            default => ConditionResult::failed('subtotal_below_minimum'),
        };
    }
}
