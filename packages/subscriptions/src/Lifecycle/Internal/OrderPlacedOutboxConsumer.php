<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Lifecycle\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Schema\TableNames;
use Commerz\Sellable\BillingPolicy;
use Commerz\Sellable\SellingPlan;
use Commerz\Stores\Data\ChannelId;
use Commerz\Subscriptions\Lifecycle\Contracts\SubscriptionContracts;
use Commerz\Subscriptions\Lifecycle\Data\CreateSubscriptionContract;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContract;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionLineInput;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Packman\Attributes\Internal;

/**
 * Outbox consumer listening to `orders.order_placed` (and `sales.order_placed`) to automatically establish
 * {@see SubscriptionContract}s and schedule recurring billing cycles.
 */
#[Internal]
final readonly class OrderPlacedOutboxConsumer implements OutboxConsumer
{
    private const array SUPPORTED_EVENTS = [
        'orders.order_placed',
        'sales.order_placed',
    ];

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $commerzOptions,
        private TableNames $tables,
        private Transactions $transactions,
        private SubscriptionContracts $contracts,
        private Clock $clock,
    ) {}

    #[Override]
    public function key(): string
    {
        return 'subscriptions.order_placed';
    }

    #[Override]
    public function consume(IntegrationEvent $event): void
    {
        if (! in_array($event->type, self::SUPPORTED_EVENTS, true)) {
            return;
        }

        $payload = $event->payload;

        if (($payload['is_renewal'] ?? false) === true || isset($payload['subscription_contract_id'])) {
            return;
        }

        $this->transactions->run(function () use ($event, $payload): void {
            $db = $this->connections->connection($this->commerzOptions->connection);
            $processedTable = $this->tables->table('subscription_processed_events');

            $alreadyProcessed = $db->table($processedTable)
                ->where('event_id', $event->eventId)
                ->exists();

            if ($alreadyProcessed) {
                return;
            }

            $groups = $this->extractRecurringPlanGroups($event, $payload);

            foreach ($groups as $group) {
                $this->contracts->create($group);
            }

            $db->table($processedTable)->insert([
                'id' => Uuid7::generate(),
                'event_id' => $event->eventId,
                'event_type' => $event->type,
                'aggregate_ref' => $event->aggregateRef,
                'processed_at' => PgTimestamp::now($this->clock),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<CreateSubscriptionContract>
     */
    private function extractRecurringPlanGroups(IntegrationEvent $event, array $payload): array
    {
        $rawLines = isset($payload['lines']) && is_array($payload['lines']) ? $payload['lines'] : [];
        if ($rawLines === []) {
            return [];
        }

        $rawChannelId = isset($payload['channel_id']) && is_string($payload['channel_id']) ? $payload['channel_id'] : null;
        $rawCurrency = isset($payload['currency']) && is_string($payload['currency']) ? $payload['currency'] : null;
        $rawCustomer = isset($payload['customer_ref']) && is_string($payload['customer_ref']) ? $payload['customer_ref'] : null;

        if ($rawChannelId === null || $rawCurrency === null || $rawCustomer === null) {
            return [];
        }

        $channelId = new ChannelId($rawChannelId);
        $currency = Currency::of($rawCurrency);
        $customer = CustomerRef::fromString($rawCustomer);
        $locale = isset($payload['locale']) && is_string($payload['locale']) && trim($payload['locale']) !== ''
            ? $payload['locale']
            : 'en';
        $paymentMethodRef = isset($payload['payment_method_ref']) && is_string($payload['payment_method_ref'])
            ? $payload['payment_method_ref']
            : null;
        $originOrderRef = $this->resolveOrderRef($event, $payload);

        $orderPlan = null;
        if (isset($payload['selling_plan']) && is_array($payload['selling_plan'])) {
            /** @var array<string, mixed> $planArray */
            $planArray = $payload['selling_plan'];
            $orderPlan = SellingPlan::fromArray($planArray);
        }

        /** @var array<string, SellingPlan> $plansByKey */
        $plansByKey = [];
        /** @var array<string, list<SubscriptionLineInput>> $linesByKey */
        $linesByKey = [];
        /** @var array<string, string> $firstLineIdByKey */
        $firstLineIdByKey = [];
        /** @var array<string, Money> $billedTotalByKey */
        $billedTotalByKey = [];

        foreach ($rawLines as $index => $rawLine) {
            if (! is_array($rawLine)) {
                continue;
            }

            /** @var array<string, mixed> $lineData */
            $lineData = $rawLine;
            $linePlan = $orderPlan;
            if (isset($lineData['selling_plan']) && is_array($lineData['selling_plan'])) {
                /** @var array<string, mixed> $rawLinePlan */
                $rawLinePlan = $lineData['selling_plan'];
                $linePlan = SellingPlan::fromArray($rawLinePlan);
            }

            if ($linePlan === null || $linePlan->billingPolicy !== BillingPolicy::Recurring) {
                continue;
            }

            $lineId = isset($lineData['line_id']) && is_string($lineData['line_id']) && $lineData['line_id'] !== ''
                ? $lineData['line_id']
                : 'line-'.($index + 1);

            $sellableRef = isset($lineData['sellable_ref']) && is_string($lineData['sellable_ref'])
                ? SellableRef::fromString($lineData['sellable_ref'])
                : SellableRef::of('item', $lineId);

            $sku = isset($lineData['sku']) && is_string($lineData['sku']) && $lineData['sku'] !== ''
                ? $lineData['sku']
                : $sellableRef->id;

            $name = isset($lineData['name']) && is_string($lineData['name']) && trim($lineData['name']) !== ''
                ? $lineData['name']
                : $sku;

            $kind = isset($lineData['kind']) && is_string($lineData['kind']) && SellableKind::tryFrom($lineData['kind']) !== null
                ? SellableKind::from($lineData['kind'])
                : SellableKind::Physical;

            $requiresShipping = isset($lineData['requires_shipping']) && is_bool($lineData['requires_shipping'])
                ? $lineData['requires_shipping']
                : ($kind === SellableKind::Physical);

            $quantity = isset($lineData['quantity']) && is_int($lineData['quantity']) && $lineData['quantity'] >= 1
                ? $lineData['quantity']
                : 1;

            $baseUnitPriceMinor = isset($lineData['base_unit_price_minor']) && is_int($lineData['base_unit_price_minor'])
                ? $lineData['base_unit_price_minor']
                : (isset($lineData['unit_price_minor']) && is_int($lineData['unit_price_minor']) ? $lineData['unit_price_minor'] : 0);

            $baseUnitPrice = Money::ofMinor($baseUnitPriceMinor, $currency);

            $lineTotalMinor = isset($lineData['total_minor']) && is_int($lineData['total_minor'])
                ? $lineData['total_minor']
                : ($linePlan->unitPriceForCycle($baseUnitPrice, 1)->multiply($quantity)->amountMinor);

            $taxClass = isset($lineData['tax_class']) && is_string($lineData['tax_class']) ? $lineData['tax_class'] : null;
            $weightGrams = isset($lineData['weight_grams']) && is_int($lineData['weight_grams']) ? $lineData['weight_grams'] : null;

            $snapshot = new SellableSnapshot(
                ref: $sellableRef,
                sku: $sku,
                name: $name,
                kind: $kind,
                requiresShipping: $requiresShipping,
                basePrice: $baseUnitPrice,
                taxClass: $taxClass,
                weightGrams: $weightGrams,
            );

            $planKey = $linePlan->id;
            $plansByKey[$planKey] = $linePlan;
            $linesByKey[$planKey][] = new SubscriptionLineInput($lineId, $snapshot, $quantity, $baseUnitPrice);
            $firstLineIdByKey[$planKey] ??= $lineId;
            $billedTotalByKey[$planKey] = ($billedTotalByKey[$planKey] ?? Money::zero($currency))
                ->plus(Money::ofMinor($lineTotalMinor, $currency));
        }

        $commands = [];
        foreach ($plansByKey as $planKey => $plan) {
            $firstLineId = $firstLineIdByKey[$planKey];
            $commands[] = new CreateSubscriptionContract(
                channelId: $channelId,
                currency: $currency,
                customer: $customer,
                sellingPlan: $plan,
                lines: $linesByKey[$planKey],
                paymentMethodRef: $paymentMethodRef,
                originOrderRef: $originOrderRef,
                originLineId: $firstLineId,
                locale: $locale,
                startedAt: $event->occurredAt,
                idempotencyKey: "outbox:{$originOrderRef}:{$planKey}",
                initialBilledAmount: $billedTotalByKey[$planKey],
            );
        }

        return $commands;
    }

    /** @param array<string, mixed> $payload */
    private function resolveOrderRef(IntegrationEvent $event, array $payload): string
    {
        if (isset($payload['order_ref']) && is_string($payload['order_ref']) && $payload['order_ref'] !== '') {
            return $payload['order_ref'];
        }

        if (isset($payload['order_id']) && is_string($payload['order_id']) && $payload['order_id'] !== '') {
            return str_contains($payload['order_id'], ':') ? $payload['order_id'] : 'order:'.$payload['order_id'];
        }

        return $event->aggregateRef;
    }
}
