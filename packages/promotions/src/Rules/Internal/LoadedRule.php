<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal;

use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Data\RuleParams;

/** A stored rule brought up to its plugin's current version, or the reason it cannot be: the promotion is then not applied. */
final readonly class LoadedRule
{
    private function __construct(
        public string $key,
        public PromotionCondition|PromotionEffect|null $rule,
        public ?RuleParams $params,
        public ?string $reason,
    ) {}

    public static function loaded(string $key, PromotionCondition|PromotionEffect $rule, RuleParams $params): self
    {
        return new self($key, $rule, $params, null);
    }

    public static function unsupported(string $key, string $reason): self
    {
        return new self($key, null, null, $reason);
    }

    public function isSupported(): bool
    {
        return $this->rule !== null;
    }
}
