<?php

declare(strict_types=1);

namespace Commerz\Promotions\Evaluation\Internal;

use Commerz\Money\Money;
use Commerz\Promotions\Definitions\Enums\Stacking;
use Commerz\Promotions\Rules\Data\Activation;
use Commerz\Promotions\Rules\Data\PromotionSummary;
use Commerz\Promotions\Rules\Internal\LoadedRule;
use Packman\Attributes\Internal;

/**
 * A promotion live for one quote, as loaded once for its session: the summary rules and activators see, how it stacks,
 * a snapshot of its limits and counters (a hint only: the limits hold when the order is placed), its rules brought up
 * to their plugins' versions, and how it was switched on. Not switched on (`$activation` null and no unsupported
 * activator) means it is not tried at all.
 */
#[Internal]
final readonly class CandidatePromotion
{
    /**
     * @param  list<LoadedRule>  $conditions  in declared order
     * @param  list<LoadedRule>  $effects  in declared order
     * @param  int  $customerUsed  orders of the quote's customer with it, read only while it has a per-customer limit
     * @param  string|null  $unsupportedActivator  why its activator cannot run (no plugin contributes the key)
     */
    public function __construct(
        public PromotionSummary $summary,
        public Stacking $stacking,
        public bool $stopFurther,
        public ?int $usageLimit,
        public ?int $perCustomerLimit,
        public int $used,
        public int $customerUsed,
        public ?Money $budget,
        public ?Money $spent,
        public array $conditions,
        public array $effects,
        public ?Activation $activation = null,
        public ?string $unsupportedActivator = null,
    ) {}

    public function activated(Activation $activation): self
    {
        return new self(
            $this->summary,
            $this->stacking,
            $this->stopFurther,
            $this->usageLimit,
            $this->perCustomerLimit,
            $this->used,
            $this->customerUsed,
            $this->budget,
            $this->spent,
            $this->conditions,
            $this->effects,
            $activation,
        );
    }

    public function withUnsupportedActivator(string $reason): self
    {
        return new self(
            $this->summary,
            $this->stacking,
            $this->stopFurther,
            $this->usageLimit,
            $this->perCustomerLimit,
            $this->used,
            $this->customerUsed,
            $this->budget,
            $this->spent,
            $this->conditions,
            $this->effects,
            null,
            $reason,
        );
    }

    /** Switched on, or with an activator that cannot run: either way it gets a verdict. */
    public function isTried(): bool
    {
        return $this->activation instanceof Activation || $this->unsupportedActivator !== null;
    }

    public function couponCode(): ?string
    {
        return $this->activation?->couponCode;
    }

    /** The code as the buyer presented it, for a rejection the buyer reads. */
    public function presentedCode(): ?string
    {
        return $this->activation instanceof Activation ? $this->activation->presentedCode ?? $this->activation->couponCode : null;
    }

    /** Why a rule or the activator cannot run, if one cannot. */
    public function unsupported(): ?string
    {
        if ($this->unsupportedActivator !== null) {
            return $this->unsupportedActivator;
        }

        foreach ([...$this->conditions, ...$this->effects] as $rule) {
            if (! $rule->isSupported()) {
                return "rule '{$rule->key}': {$rule->reason}";
            }
        }

        return null;
    }
}
