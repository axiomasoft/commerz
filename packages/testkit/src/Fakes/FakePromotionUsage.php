<?php

declare(strict_types=1);

namespace Commerz\Testkit\Fakes;

use Commerz\Attributes\Api;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Promotions\Data\AppliedPromotion;
use Commerz\Ports\Promotions\Data\ConsumePromotionUsage;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;

/** Counts uses per promotion with optional total limits; consume is all-or-nothing, both sides idempotent per order. */
#[Api(since: '0.1')]
final class FakePromotionUsage implements PromotionUsage
{
    /** @var array<string, int> promotion id => total limit */
    private array $limits = [];

    /** @var array<string, int> promotion id => uses */
    private array $uses = [];

    /** @var array<string, ConsumePromotionUsage> order ref => consumed usage */
    public private(set) array $consumed = [];

    /** @var list<RestorePromotionUsage> */
    public private(set) array $restored = [];

    public function limit(string $promotionId, int $total): self
    {
        $this->limits[$promotionId] = $total;

        return $this;
    }

    public function uses(string $promotionId): int
    {
        return $this->uses[$promotionId] ?? 0;
    }

    public function consume(ConsumePromotionUsage $command): void
    {
        if (isset($this->consumed[$command->orderRef])) {
            return;
        }

        foreach ($command->applied as $applied) {
            $limit = $this->limits[$applied->promotionId] ?? null;

            if ($limit !== null && $this->uses($applied->promotionId) >= $limit) {
                throw new UsageLimitReached($applied->promotionId, $applied->couponCode, 'total');
            }
        }

        foreach ($command->applied as $applied) {
            $this->uses[$applied->promotionId] = $this->uses($applied->promotionId) + 1;
        }

        $this->consumed[$command->orderRef] = $command;
    }

    public function restore(RestorePromotionUsage $command): void
    {
        $consumed = $this->consumed[$command->orderRef] ?? null;

        if ($consumed === null) {
            return;
        }

        $this->restored[] = $command;
        unset($this->consumed[$command->orderRef]);

        array_map(function (AppliedPromotion $applied): void {
            $this->uses[$applied->promotionId] = max(0, $this->uses($applied->promotionId) - 1);
        }, $consumed->applied);
    }
}
