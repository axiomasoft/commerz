<?php

declare(strict_types=1);

namespace Commerz\Sellable;

use Commerz\Errors\InvalidCommand;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;

/**
 * Fulfillment cadence or release schedule of a {@see SellingPlan}:
 * - immediate one-time fulfillment
 * - recurring interval delivery (e.g. every 1 month, every 2 weeks)
 * - scheduled release date (pre-order) or lead-time days after checkout
 */
#[Api(since: '0.1')]
final readonly class DeliveryPolicy
{
    public ?DateTimeImmutable $fulfillAt;

    public function __construct(
        public ?SellingPlanInterval $interval = null,
        public ?int $intervalCount = null,
        ?DateTimeImmutable $fulfillAt = null,
        public ?int $fulfillAfterDays = null,
    ) {
        if (($interval === null) !== ($intervalCount === null)) {
            throw new InvalidCommand(
                'deliveryPolicy',
                'interval and intervalCount must be provided together',
                'sellable.invalid_delivery_policy',
            );
        }

        if ($intervalCount !== null && $intervalCount < 1) {
            throw new InvalidCommand('intervalCount', 'must be at least 1', 'sellable.invalid_delivery_policy');
        }

        if ($fulfillAfterDays !== null && $fulfillAfterDays < 1) {
            throw new InvalidCommand('fulfillAfterDays', 'must be at least 1', 'sellable.invalid_delivery_policy');
        }

        $modes = ($interval !== null ? 1 : 0) + ($fulfillAt !== null ? 1 : 0) + ($fulfillAfterDays !== null ? 1 : 0);
        if ($modes > 1) {
            throw new InvalidCommand(
                'deliveryPolicy',
                'cannot combine recurring interval, fulfillAt, and fulfillAfterDays on one DeliveryPolicy',
                'sellable.invalid_delivery_policy',
            );
        }

        $this->fulfillAt = $fulfillAt?->setTimezone(new DateTimeZone('UTC'));
    }

    public static function immediate(): self
    {
        return new self;
    }

    public static function every(SellingPlanInterval $interval, int $intervalCount = 1): self
    {
        return new self(interval: $interval, intervalCount: $intervalCount);
    }

    public static function onDate(DateTimeImmutable $fulfillAt): self
    {
        return new self(fulfillAt: $fulfillAt);
    }

    public static function afterDays(int $days): self
    {
        return new self(fulfillAfterDays: $days);
    }

    public function isRecurring(): bool
    {
        return $this->interval !== null;
    }

    public function isImmediate(): bool
    {
        return $this->interval === null && $this->fulfillAt === null && $this->fulfillAfterDays === null;
    }

    public function nextDeliveryAt(DateTimeImmutable $anchor, int $cycles = 1): DateTimeImmutable
    {
        if ($cycles < 1) {
            throw new InvalidCommand('cycles', 'must be at least 1', 'sellable.invalid_cycle');
        }

        $utc = $anchor->setTimezone(new DateTimeZone('UTC'));

        if ($this->interval !== null && $this->intervalCount !== null) {
            return $this->interval->addTo($utc, $this->intervalCount * $cycles);
        }

        if ($this->fulfillAt !== null) {
            return $this->fulfillAt;
        }

        if ($this->fulfillAfterDays !== null) {
            return $utc->add(new DateInterval("P{$this->fulfillAfterDays}D"));
        }

        return $utc;
    }

    public function equals(self $other): bool
    {
        return $this->interval === $other->interval
            && $this->intervalCount === $other->intervalCount
            && $this->fulfillAt?->format('Y-m-d\TH:i:s.uP') === $other->fulfillAt?->format('Y-m-d\TH:i:s.uP')
            && $this->fulfillAfterDays === $other->fulfillAfterDays;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'interval' => $this->interval?->value,
            'interval_count' => $this->intervalCount,
            'fulfill_at' => $this->fulfillAt?->format('Y-m-d\TH:i:s.uP'),
            'fulfill_after_days' => $this->fulfillAfterDays,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $interval = isset($data['interval']) && is_string($data['interval'])
            ? (SellingPlanInterval::tryFrom($data['interval'])
                ?? throw new InvalidCommand('deliveryPolicy.interval', "unknown interval '{$data['interval']}'", 'sellable.invalid_delivery_policy'))
            : null;

        $intervalCount = isset($data['interval_count']) && is_int($data['interval_count']) ? $data['interval_count'] : null;
        $fulfillAt = isset($data['fulfill_at']) && is_string($data['fulfill_at'])
            ? new DateTimeImmutable($data['fulfill_at'])
            : null;
        $fulfillAfterDays = isset($data['fulfill_after_days']) && is_int($data['fulfill_after_days'])
            ? $data['fulfill_after_days']
            : null;

        return new self(
            interval: $interval,
            intervalCount: $intervalCount,
            fulfillAt: $fulfillAt,
            fulfillAfterDays: $fulfillAfterDays,
        );
    }
}
