<?php

declare(strict_types=1);

namespace Commerz\Coupons\Internal\Conditions;

use Commerz\Coupons\Data\CouponCode;
use Commerz\Promotions\Contracts\ConditionConfig;
use Commerz\Promotions\Contracts\PromotionCondition;
use Commerz\Promotions\Data\ConditionResult;
use Commerz\Promotions\Data\PromotionInput;
use Commerz\Promotions\Exceptions\InvalidPromotionConfig;
use Override;

final readonly class CodeInConfig implements ConditionConfig
{
    /** @param list<string> $codes нормализованные */
    public function __construct(public array $codes) {}

    #[Override]
    public static function fromArray(array $raw): static
    {
        $codes = $raw['codes'] ?? null;
        if (! is_array($codes) || $codes === [] || array_filter($codes, is_string(...)) !== $codes) {
            throw InvalidPromotionConfig::field('codes', 'non-empty list of strings');
        }

        return new static(array_values(array_unique(array_map(static fn (string $c): string => CouponCode::normalize($c)->value, $codes))));
    }

    #[Override]
    public function toArray(): array
    {
        return ['codes' => $this->codes];
    }

    #[Override]
    public static function upgrade(int $fromVersion, array $raw): array
    {
        return $raw;
    }
}

/** «Скидка, если введён любой из кодов» — без лимитов кода (лимиты — только у cmz_coupons). */
final readonly class CodeIn implements PromotionCondition
{
    #[Override]
    public function key(): string
    {
        return 'coupon.code_in';
    }

    #[Override]
    public function configClass(): string
    {
        return CodeInConfig::class;
    }

    #[Override]
    public function evaluate(ConditionConfig $config, PromotionInput $input): ConditionResult
    {
        assert($config instanceof CodeInConfig);

        return array_intersect($config->codes, $input->enteredCodes) !== []
            ? ConditionResult::matched()
            : ConditionResult::failed('code_not_entered');
    }
}
