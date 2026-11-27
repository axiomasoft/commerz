<?php

declare(strict_types=1);

namespace Commerz\Quotes\Internal;

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Quotes\Contracts\CartTransformOperation;
use Commerz\Quotes\Contracts\CartTransformRule;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Contracts\QuoteStagePolicy;
use Commerz\Quotes\Data\DeliverySelection;
use Commerz\Quotes\Data\LineExpand;
use Commerz\Quotes\Data\LinesMerge;
use Commerz\Quotes\Data\LineUpdate;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\Rejection;
use Commerz\Quotes\Enums\FailurePolicy;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Illuminate\Contracts\Container\Container;
use Override;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Throwable;

/**
 * Stage 01 (`cart_transform`): runs registered {@see CartTransformRule} contributions in deterministic key order
 * before `BaseStage` prices the lines. Enforces `FailurePolicy::BlockOnFailure` by default so failed or conflicting
 * bundle/merge rules abort the quote rather than leaving unexpanded or mispriced lines.
 */
#[Internal]
final readonly class CartTransformStage implements QuoteStage, QuoteStagePolicy
{
    public function __construct(
        private Container $container,
    ) {}

    #[Override]
    public function key(): string
    {
        return QuoteAnchor::CartTransform->value;
    }

    #[Override]
    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::CartTransform;
    }

    #[Override]
    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    #[Override]
    public function failurePolicy(): FailurePolicy
    {
        return FailurePolicy::BlockOnFailure;
    }

    #[Override]
    public function timeoutMilliseconds(): ?int
    {
        return null;
    }

    #[Override]
    public function apply(QuoteState $state): QuoteState
    {
        if ($state->input->lines === []) {
            return $state;
        }

        $contributions = $this->container->make(Contributions::class);
        $keys = $contributions->keys(CartTransformRule::KIND);

        if ($keys === []) {
            return $state;
        }

        sort($keys, SORT_STRING);
        $originalLines = $state->input->lines;
        $currentInput = $state->input;
        $changed = false;

        foreach ($keys as $key) {
            $rule = $contributions->get(CartTransformRule::KIND, $key, CartTransformRule::class);
            $policy = $rule instanceof QuoteStagePolicy ? $rule->failurePolicy() : FailurePolicy::BlockOnFailure;
            $timeoutMs = $rule instanceof QuoteStagePolicy ? $rule->timeoutMilliseconds() : null;
            $startedAt = hrtime(true);

            try {
                $operations = $rule->transform($currentInput);
                $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

                if ($timeoutMs !== null && $elapsedMs > $timeoutMs) {
                    throw QuoteFailed::timedOut($rule->key(), $timeoutMs);
                }

                if ($operations === []) {
                    continue;
                }

                $nextLines = $this->applyOperations($rule->key(), $currentInput, $operations);
                $currentInput = $currentInput->withLines($nextLines);
                $changed = true;
            } catch (QuoteFailed $failed) {
                if ($policy === FailurePolicy::IgnoreOnFailure) {
                    $state = $state->withRejection(new Rejection($rule->key(), $failed->reason, $failed->getMessage()));

                    continue;
                }

                throw $failed;
            } catch (Throwable $error) {
                if ($policy === FailurePolicy::IgnoreOnFailure) {
                    $state = $state->withRejection(new Rejection($rule->key(), 'stage_error', $error->getMessage()));

                    continue;
                }

                throw QuoteFailed::byStage($rule->key(), $error);
            }
        }

        if (! $changed) {
            return $state;
        }

        $delivery = $currentInput->delivery;

        if ($delivery !== null && $delivery->parcel->weightGrams === self::shippingWeight($originalLines)) {
            $delivery = new DeliverySelection(
                $delivery->methodCode,
                $delivery->address,
                new Parcel(
                    self::shippingWeight($currentInput->lines),
                    $delivery->parcel->value,
                    $delivery->parcel->lengthMm,
                    $delivery->parcel->widthMm,
                    $delivery->parcel->heightMm,
                ),
            );
            $currentInput = $currentInput->withLines($currentInput->lines, $delivery);
        }

        return $state->withInput($currentInput);
    }

    /**
     * @param  list<CartTransformOperation>  $operations
     * @return list<QuoteLineInput>
     */
    private function applyOperations(string $ruleKey, QuoteInput $input, array $operations): array
    {
        $snapshots = $this->resolveSellables($operations, $input->context);
        $currency = $input->context->currency;

        /** @var array<string, QuoteLineInput> $byId */
        $byId = [];
        foreach ($input->lines as $line) {
            $byId[$line->lineId] = $line;
        }

        /** @var array<string, LineExpand> $expands */
        $expands = [];
        /** @var array<string, LineUpdate> $updates */
        $updates = [];
        /** @var array<string, int> $mergedQuantities lineId => total units consumed */
        $mergedQuantities = [];
        /** @var array<string, list<QuoteLineInput>> $mergeInsertions first target lineId => merged bundle lines */
        $mergeInsertions = [];
        /** @var array<string, true> $touched */
        $touched = [];

        foreach ($operations as $opIndex => $operation) {
            if ($operation instanceof LineExpand) {
                $this->claimLine($ruleKey, $operation->lineId, $byId, $touched);
                $expands[$operation->lineId] = $operation;

                continue;
            }

            if ($operation instanceof LineUpdate) {
                $this->claimLine($ruleKey, $operation->lineId, $byId, $touched);
                $updates[$operation->lineId] = $operation;

                continue;
            }

            if ($operation instanceof LinesMerge) {
                $targetSignatures = [];

                foreach ($operation->targets as $target) {
                    $source = $byId[$target->lineId] ?? throw new QuoteFailed(
                        $ruleKey,
                        'unknown_line',
                        "merge references unknown line '{$target->lineId}'",
                    );

                    if (isset($expands[$target->lineId]) || isset($updates[$target->lineId])) {
                        throw new QuoteFailed(
                            $ruleKey,
                            'conflicting_transform',
                            "line '{$target->lineId}' is modified by multiple transform operations",
                        );
                    }

                    $take = $target->quantity ?? $source->quantity;
                    $alreadyTaken = $mergedQuantities[$target->lineId] ?? 0;

                    if ($alreadyTaken + $take > $source->quantity) {
                        throw new QuoteFailed(
                            $ruleKey,
                            'invalid_transform',
                            "merge consumes more units of line '{$target->lineId}' than available ({$source->quantity})",
                        );
                    }

                    $mergedQuantities[$target->lineId] = $alreadyTaken + $take;
                    $touched[$target->lineId] = true;
                    $targetSignatures[] = "{$target->lineId}:{$take}";
                }

                $parentSnapshot = $this->snapshotOf($operation->parentSellable, $snapshots);
                self::assertOptionalCurrency($ruleKey, $operation->fixedUnitPrice, $currency);

                $firstTargetId = $operation->targets[0]->lineId;
                $mergedLineId = $operation->lineId ?? self::deterministicUuid(
                    "{$ruleKey}:merge:{$opIndex}:".implode('|', $targetSignatures).':'.$parentSnapshot->ref,
                );

                $mergeInsertions[$firstTargetId][] = new QuoteLineInput(
                    lineId: $mergedLineId,
                    sellable: $parentSnapshot,
                    quantity: $operation->quantity,
                    fixedUnitPrice: $operation->fixedUnitPrice,
                    taxIncluded: $operation->taxIncluded,
                    priceSource: $operation->fixedUnitPrice !== null ? "cart_transform:{$ruleKey}" : null,
                );

                continue;
            }

            throw new QuoteFailed(
                $ruleKey,
                'unsupported_transform',
                'unsupported cart transform operation '.$operation::class,
            );
        }

        $result = [];

        foreach ($input->lines as $line) {
            $lineId = $line->lineId;

            if (isset($expands[$lineId])) {
                $expand = $expands[$lineId];

                foreach ($expand->components as $compIndex => $component) {
                    $componentSnapshot = $this->snapshotOf($component->sellable, $snapshots);
                    self::assertOptionalCurrency($ruleKey, $component->fixedUnitPrice, $currency);

                    $expandedId = $component->lineId ?? self::deterministicUuid(
                        "{$ruleKey}:expand:{$lineId}:{$compIndex}:".$componentSnapshot->ref,
                    );

                    $result[] = new QuoteLineInput(
                        lineId: $expandedId,
                        sellable: $componentSnapshot,
                        quantity: $line->quantity * $component->quantityPerUnit,
                        fixedUnitPrice: $component->fixedUnitPrice,
                        taxIncluded: $component->taxIncluded,
                        priceSource: $component->fixedUnitPrice !== null ? "cart_transform:{$ruleKey}" : null,
                    );
                }

                continue;
            }

            if (isset($updates[$lineId])) {
                $update = $updates[$lineId];
                self::assertOptionalCurrency($ruleKey, $update->fixedUnitPrice, $currency);

                $snapshot = $update->sellable !== null
                    ? $this->snapshotOf($update->sellable, $snapshots)
                    : $line->sellable;

                if ($update->title !== null) {
                    $snapshot = self::withTitle($snapshot, $update->title);
                }

                $fixedPrice = $update->fixedUnitPrice ?? $line->fixedUnitPrice;
                $taxIncluded = $update->taxIncluded ?? $line->taxIncluded;
                $priceSource = $update->fixedUnitPrice !== null ? "cart_transform:{$ruleKey}" : $line->priceSource;

                $result[] = new QuoteLineInput(
                    lineId: $lineId,
                    sellable: $snapshot,
                    quantity: $update->quantity ?? $line->quantity,
                    fixedUnitPrice: $fixedPrice,
                    taxIncluded: $taxIncluded,
                    priceSource: $priceSource,
                );

                continue;
            }

            if (isset($mergeInsertions[$lineId])) {
                foreach ($mergeInsertions[$lineId] as $mergedLine) {
                    $result[] = $mergedLine;
                }
            }

            if (isset($mergedQuantities[$lineId])) {
                $remaining = $line->quantity - $mergedQuantities[$lineId];

                if ($remaining > 0) {
                    $result[] = new QuoteLineInput(
                        lineId: $lineId,
                        sellable: $line->sellable,
                        quantity: $remaining,
                        fixedUnitPrice: $line->fixedUnitPrice,
                        taxIncluded: $line->taxIncluded,
                        priceSource: $line->priceSource,
                    );
                }

                continue;
            }

            $result[] = $line;
        }

        return $result;
    }

    /**
     * @param  array<string, QuoteLineInput>  $byId
     * @param  array<string, true>  $touched
     */
    private function claimLine(string $ruleKey, string $lineId, array $byId, array &$touched): void
    {
        if (! isset($byId[$lineId])) {
            throw new QuoteFailed($ruleKey, 'unknown_line', "transform references unknown line '{$lineId}'");
        }

        if (isset($touched[$lineId])) {
            throw new QuoteFailed($ruleKey, 'conflicting_transform', "line '{$lineId}' is modified by multiple transform operations");
        }

        $touched[$lineId] = true;
    }

    /**
     * @param  list<CartTransformOperation>  $operations
     * @return array<string, SellableSnapshot>
     */
    private function resolveSellables(array $operations, CommerceContext $context): array
    {
        $refs = [];

        foreach ($operations as $operation) {
            if ($operation instanceof LineExpand) {
                foreach ($operation->components as $component) {
                    if ($component->sellable instanceof SellableRef) {
                        $refs[(string) $component->sellable] = $component->sellable;
                    }
                }
            } elseif ($operation instanceof LinesMerge && $operation->parentSellable instanceof SellableRef) {
                $refs[(string) $operation->parentSellable] = $operation->parentSellable;
            } elseif ($operation instanceof LineUpdate && $operation->sellable instanceof SellableRef) {
                $refs[(string) $operation->sellable] = $operation->sellable;
            }
        }

        if ($refs === []) {
            return [];
        }

        return $this->container->make(Sellables::class)->resolveMany(array_values($refs), $context);
    }

    /**
     * @param  array<string, SellableSnapshot>  $resolved
     */
    private function snapshotOf(SellableSnapshot|SellableRef $sellable, array $resolved): SellableSnapshot
    {
        if ($sellable instanceof SellableSnapshot) {
            return $sellable;
        }

        return $resolved[(string) $sellable];
    }

    private static function assertOptionalCurrency(string $ruleKey, ?Money $price, Currency $currency): void
    {
        if ($price !== null && ! $price->currency->equals($currency)) {
            throw new QuoteFailed(
                $ruleKey,
                'currency_mismatch',
                "transform price currency {$price->currency->code} differs from quote currency {$currency->code}",
            );
        }
    }

    private static function withTitle(SellableSnapshot $snapshot, string $title): SellableSnapshot
    {
        return new SellableSnapshot(
            ref: $snapshot->ref,
            sku: $snapshot->sku,
            name: $title,
            kind: $snapshot->kind,
            requiresShipping: $snapshot->requiresShipping,
            purchasable: $snapshot->purchasable,
            unavailableReason: $snapshot->unavailableReason,
            options: $snapshot->options,
            basePrice: $snapshot->basePrice,
            taxClass: $snapshot->taxClass,
            weightGrams: $snapshot->weightGrams,
            attributes: $snapshot->attributes,
            categories: $snapshot->categories,
            quantityStepPolicy: $snapshot->quantityStepPolicy,
            unitPrice: $snapshot->unitPrice,
            hsCode: $snapshot->hsCode,
            originCountryCode: $snapshot->originCountryCode,
            material: $snapshot->material,
            customsDescription: $snapshot->customsDescription,
            sellerRef: $snapshot->sellerRef,
        );
    }

    /**
     * Deterministic RFC 9562 version-5 UUID so transformed lines pass UUID `OrderLineId` / DB `uuid` checks
     * while keeping `QuotePipeline::run()` completely pure and deterministic.
     */
    private static function deterministicUuid(string $seed): string
    {
        $bytes = substr(sha1('commerz.cart_transform:'.$seed, true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'
            .substr($hex, 8, 4).'-'
            .substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'
            .substr($hex, 20, 12);
    }

    /**
     * @param  list<QuoteLineInput>  $lines
     */
    private static function shippingWeight(array $lines): int
    {
        $weight = 0;

        foreach ($lines as $line) {
            if ($line->sellable->requiresShipping) {
                $weight += ($line->sellable->weightGrams ?? 0) * $line->quantity;
            }
        }

        return $weight;
    }
}
