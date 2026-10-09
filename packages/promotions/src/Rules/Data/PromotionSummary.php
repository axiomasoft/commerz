<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use Commerz\Attributes\Api;
use Commerz\Money\Currency;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Rules\Enums\PromotionStage;

/** The part of a promotion a rule or an activator may look at. */
#[Api(since: '0.1')]
final readonly class PromotionSummary
{
    public function __construct(
        public PromotionId $id,
        public string $code,
        public string $name,
        public PromotionStage $stage,
        public string $activator,
        public int $priority,
        public ?Currency $currency,
    ) {}
}
