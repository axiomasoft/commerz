<?php

declare(strict_types=1);

namespace Commerz\Promotions\Data;

use Commerz\Attributes\Api;
use Commerz\Context\Data\CommerceContext;
use Commerz\Money\Data\Money;
use Commerz\Pricing\Data\PricedLine;
use Commerz\Promotions\Contracts\ConditionConfig;
use Commerz\Promotions\Contracts\EffectConfig;
use Commerz\Promotions\Enums\PromotionStage;
use Commerz\Promotions\Enums\RejectionReason;
use Commerz\Promotions\Enums\StackingMode;

/** Неизменяемый вход условий и эффектов: текущее состояние строк на момент применения акции. */
#[Api(since: '1.0')]
final readonly class PromotionInput
{
    /**
     * @param list<PricedLine> $lines
     * @param list<string> $enteredCodes
     */
    public function __construct(
        public CommerceContext $context,
        public array $lines,
        public Money $itemsTotal,
        public array $enteredCodes,
    ) {}
}

#[Api(since: '1.0')]
final readonly class ConditionResult
{
    private function __construct(public bool $matched, public ?string $reason) {}

    public static function matched(): self
    {
        return new self(true, null);
    }

    public static function failed(string $reason): self
    {
        return new self(false, $reason);
    }
}

/** Предложение эффекта; движок обрежет, чтобы строка не ушла в минус, и превратит в Adjustment. */
#[Api(since: '1.0')]
final readonly class AdjustmentProposal
{
    public function __construct(public ?string $lineId, public Money $amount) {}
}

/** Сконфигурированное условие/эффект: ключ плагина + гидратированный типизированный конфиг. */
#[Api(since: '1.0')]
final readonly class ConfiguredCondition
{
    public function __construct(public string $key, public ConditionConfig $config) {}
}

#[Api(since: '1.0')]
final readonly class ConfiguredEffect
{
    public function __construct(public string $key, public EffectConfig $config) {}
}

/** Акция-кандидат: загружена репозиторием (status=active, окно дат, канал) или активирована кодом. */
#[Api(since: '1.0')]
final readonly class PromotionCandidate
{
    /**
     * @param list<ConfiguredCondition> $conditions
     * @param list<ConfiguredEffect> $effects
     */
    public function __construct(
        public string $id,
        public int $revision,
        public string $code,
        public string $label,
        public PromotionStage $stage,
        public StackingMode $stacking,
        public int $priority,
        public array $conditions,
        public array $effects,
        public ?string $activatedByCode = null,
    ) {}

    public function source(): string
    {
        return "promotion:{$this->id}@{$this->revision}";
    }

    public function activatedBy(string $code): self
    {
        return new self(
            $this->id, $this->revision, $this->code, $this->label, $this->stage, $this->stacking,
            $this->priority, $this->conditions, $this->effects, activatedByCode: $code,
        );
    }
}

#[Api(since: '1.0')]
final readonly class ActivationRequest
{
    /** @param list<string> $codes нормализованные коды корзины */
    public function __construct(public CommerceContext $context, public array $codes) {}
}

#[Api(since: '1.0')]
final readonly class ActivationRejection
{
    public function __construct(public string $code, public RejectionReason $reason) {}
}

#[Api(since: '1.0')]
final readonly class ActivationResult
{
    /**
     * @param list<PromotionCandidate> $candidates
     * @param list<ActivationRejection> $rejections
     */
    public function __construct(public array $candidates, public array $rejections) {}
}

/** Передаётся активатору внутри транзакции размещения заказа. */
#[Api(since: '1.0')]
final readonly class ActivationCommit
{
    public function __construct(
        public string $orderId,
        public string $promotionId,
        public string $code,
        public ?string $customerRef,
        public Money $discount,
    ) {}
}

#[Api(since: '1.0')]
final readonly class ActivationRevert
{
    public function __construct(public string $orderId, public string $promotionId, public string $code) {}
}
