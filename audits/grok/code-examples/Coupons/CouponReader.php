<?php

declare(strict_types=1);

namespace Commerz\Coupons\Internal;

use Commerz\Context\Data\CommerceContext;
use Commerz\Coupons\Data\CouponCode;
use Commerz\Promotions\Data\PromotionCandidate;
use Commerz\Promotions\Enums\RejectionReason;
use DateTimeImmutable;

interface CouponReader
{
    /**
     * Один запрос: cmz_coupons JOIN cmz_promotions по коду; акции гидратирует репозиторий promotions.
     *
     * @param  list<CouponCode>  $codes
     * @return array<string, CouponView> ключ — нормализованный код
     */
    public function findByCodes(array $codes, CommerceContext $context): array;
}

/** Снимок для превью корзины. Окончательная проверка лимитов — только в RedeemCouponHandler под блокировкой. */
final readonly class CouponView
{
    public function __construct(
        public string $code,
        public PromotionCandidate $promotion,
        public bool $isActive,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public bool $exhausted,
        public ?string $customerRef,
    ) {}

    public function rejectionFor(CommerceContext $context): ?RejectionReason
    {
        return match (true) {
            ! $this->isActive => RejectionReason::CodeNotFound,           // отключённый код не раскрываем
            $this->startsAt !== null && $context->at < $this->startsAt => RejectionReason::NotStarted,
            $this->endsAt !== null && $context->at >= $this->endsAt => RejectionReason::Expired,
            $this->exhausted => RejectionReason::Exhausted,
            $this->customerRef !== null && $this->customerRef !== $context->customerRef => RejectionReason::NotForCustomer,
            default => null,
        };
    }
}
