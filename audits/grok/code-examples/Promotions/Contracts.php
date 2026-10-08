<?php

declare(strict_types=1);

namespace Commerz\Promotions\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Promotions\Data\ActivationCommit;
use Commerz\Promotions\Data\ActivationRequest;
use Commerz\Promotions\Data\ActivationResult;
use Commerz\Promotions\Data\ActivationRevert;
use Commerz\Promotions\Data\AdjustmentProposal;
use Commerz\Promotions\Data\ConditionResult;
use Commerz\Promotions\Data\PromotionInput;

/** Типизированный конфиг условия: final readonly DTO, гидратация из jsonb + миграция версий. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ConditionConfig
{
    public const int VERSION = 1;

    /** @param array<string, mixed> $raw бросает InvalidPromotionConfig */
    public static function fromArray(array $raw): static;

    /** @return array<string, mixed> */
    public function toArray(): array;

    /**

     * @param array<string, mixed> $raw

     * @return array<string, mixed>

     */
    public static function upgrade(int $fromVersion, array $raw): array;
}

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface EffectConfig
{
    public const int VERSION = 1;

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): static;

    /** @return array<string, mixed> */
    public function toArray(): array;

    /**

     * @param array<string, mixed> $raw

     * @return array<string, mixed>

     */
    public static function upgrade(int $fromVersion, array $raw): array;
}

/** Чистая функция: без БД, без времени «сейчас» (только $input->context->at), без мутаций. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PromotionCondition
{
    public function key(): string;

    /** @return class-string<ConditionConfig> */
    public function configClass(): string;

    public function evaluate(ConditionConfig $config, PromotionInput $input): ConditionResult;
}

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PromotionEffect
{
    public function key(): string;

    /** @return class-string<EffectConfig> */
    public function configClass(): string;

    /** @return list<AdjustmentProposal> */
    public function propose(EffectConfig $config, PromotionInput $input): array;
}

/** Способ «включить» акцию: automatic (ядро promotions), coupon (плагин), referral/link (сторонние плагины). */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PromotionActivator
{
    public function key(): string;

    /** Батч-чтение до запуска движка. Никаких записей. */
    public function activate(ActivationRequest $request): ActivationResult;

    /** Внутри транзакции размещения заказа; бросает ActivationExhausted. */
    public function commit(ActivationCommit $commit): void;

    /** Отмена заказа при UsageReleasePolicy::OnCancel. Идемпотентно. */
    public function revert(ActivationRevert $revert): void;
}
