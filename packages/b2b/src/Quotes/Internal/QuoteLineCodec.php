<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Internal;

use Commerz\B2b\Internal\B2bCodec;
use Commerz\B2b\PaymentTerms\Data\SellingPlanSummary;
use Commerz\B2b\PaymentTerms\Enums\SellingPlanBillingKind;
use Commerz\B2b\Quotes\Data\NegotiatedQuoteLineView;
use Commerz\B2b\Quotes\Data\QuoteRequestLineInput;
use Commerz\B2b\Quotes\Data\QuoteRequestLineView;
use Commerz\B2b\Quotes\Data\StagedLineOverride;
use Commerz\B2b\Quotes\Data\StagedQuoteLineView;
use Commerz\Identity\SellableRef;
use Commerz\Identity\SellerRef;
use Commerz\Measures\QuantityStepPolicy;
use Commerz\Measures\UnitPrice;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Packman\Attributes\Internal;

#[Internal]
final class QuoteLineCodec
{
    /** @param list<QuoteRequestLineInput> $lines */
    public static function encodeRequestLines(array $lines): string
    {
        return B2bCodec::encodeJson(array_map(
            static fn (QuoteRequestLineInput $line): array => [
                'line_id' => $line->lineId,
                'sellable_ref' => (string) $line->sellable,
                'quantity' => $line->quantity,
                'target_unit_price_minor' => $line->targetUnitPrice?->amountMinor,
                'note' => $line->note,
                'selling_plan' => self::encodeSellingPlan($line->sellingPlan),
            ],
            $lines,
        ));
    }

    /** @return list<QuoteRequestLineView> */
    public static function decodeRequestLines(string $json, Currency $currency): array
    {
        $result = [];

        foreach (B2bCodec::decodeJson($json) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $lineId = isset($item['line_id']) && is_string($item['line_id']) ? $item['line_id'] : null;
            $sellable = isset($item['sellable_ref']) && is_string($item['sellable_ref']) ? $item['sellable_ref'] : null;
            $qty = isset($item['quantity']) && is_int($item['quantity']) ? $item['quantity'] : null;

            if ($lineId === null || $sellable === null || $qty === null) {
                continue;
            }

            $targetMinor = isset($item['target_unit_price_minor']) && is_int($item['target_unit_price_minor'])
                ? $item['target_unit_price_minor']
                : null;
            $note = isset($item['note']) && is_string($item['note']) ? $item['note'] : null;
            $plan = isset($item['selling_plan']) && is_array($item['selling_plan'])
                ? self::decodeSellingPlan($item['selling_plan'])
                : null;

            $result[] = new QuoteRequestLineView(
                lineId: $lineId,
                sellable: SellableRef::fromString($sellable),
                quantity: $qty,
                targetUnitPrice: $targetMinor === null ? null : Money::ofMinor($targetMinor, $currency),
                note: $note,
                sellingPlan: $plan,
            );
        }

        return $result;
    }

    /** @param list<StagedLineOverride> $overrides */
    public static function encodeOverrides(array $overrides): string
    {
        return B2bCodec::encodeJson(array_map(
            static fn (StagedLineOverride $o): array => [
                'line_id' => $o->lineId,
                'negotiated_unit_price_minor' => $o->negotiatedUnitPrice?->amountMinor,
                'quantity' => $o->quantity,
            ],
            $overrides,
        ));
    }

    /** @return list<StagedLineOverride> */
    public static function decodeOverrides(string $json, Currency $currency): array
    {
        $result = [];

        foreach (B2bCodec::decodeJson($json) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $lineId = isset($item['line_id']) && is_string($item['line_id']) ? $item['line_id'] : null;
            if ($lineId === null) {
                continue;
            }
            $priceMinor = isset($item['negotiated_unit_price_minor']) && is_int($item['negotiated_unit_price_minor'])
                ? $item['negotiated_unit_price_minor']
                : null;
            $qty = isset($item['quantity']) && is_int($item['quantity']) ? $item['quantity'] : null;

            $result[] = new StagedLineOverride(
                lineId: $lineId,
                negotiatedUnitPrice: $priceMinor === null ? null : Money::ofMinor($priceMinor, $currency),
                quantity: $qty,
            );
        }

        return $result;
    }

    /** @param list<StagedQuoteLineView> $lines */
    public static function encodeStagedLines(array $lines): string
    {
        return B2bCodec::encodeJson(array_map(
            static fn (StagedQuoteLineView $line): array => [
                'line_id' => $line->lineId,
                'sellable' => self::encodeSnapshot($line->sellable),
                'quantity' => $line->quantity,
                'catalog_unit_price_minor' => $line->catalogUnitPrice->amountMinor,
                'unit_price_minor' => $line->stagedUnitPrice->amountMinor,
                'subtotal_minor' => $line->subtotal->amountMinor,
                'tax_total_minor' => $line->taxTotal->amountMinor,
                'total_minor' => $line->total->amountMinor,
                'tax_included' => $line->taxIncluded,
                'selling_plan' => self::encodeSellingPlan($line->sellingPlan),
            ],
            $lines,
        ));
    }

    /** @return list<StagedQuoteLineView> */
    public static function decodeStagedLines(string $json, Currency $currency): array
    {
        $result = [];

        foreach (B2bCodec::decodeJson($json) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $parsed = self::parsePricedLine($item, $currency);
            if ($parsed === null) {
                continue;
            }
            $result[] = new StagedQuoteLineView(
                lineId: $parsed['line_id'],
                sellable: $parsed['sellable'],
                quantity: $parsed['quantity'],
                catalogUnitPrice: $parsed['catalog_unit_price'],
                stagedUnitPrice: $parsed['unit_price'],
                subtotal: $parsed['subtotal'],
                taxTotal: $parsed['tax_total'],
                total: $parsed['total'],
                taxIncluded: $parsed['tax_included'],
                sellingPlan: $parsed['selling_plan'],
            );
        }

        return $result;
    }

    /** @param list<NegotiatedQuoteLineView> $lines */
    public static function encodeNegotiatedLines(array $lines): string
    {
        return B2bCodec::encodeJson(array_map(
            static fn (NegotiatedQuoteLineView $line): array => [
                'line_id' => $line->lineId,
                'sellable' => self::encodeSnapshot($line->sellable),
                'quantity' => $line->quantity,
                'catalog_unit_price_minor' => $line->catalogUnitPrice->amountMinor,
                'unit_price_minor' => $line->lockedUnitPrice->amountMinor,
                'subtotal_minor' => $line->subtotal->amountMinor,
                'tax_total_minor' => $line->taxTotal->amountMinor,
                'total_minor' => $line->total->amountMinor,
                'tax_included' => $line->taxIncluded,
                'selling_plan' => self::encodeSellingPlan($line->sellingPlan),
            ],
            $lines,
        ));
    }

    /** @return list<NegotiatedQuoteLineView> */
    public static function decodeNegotiatedLines(string $json, Currency $currency): array
    {
        $result = [];

        foreach (B2bCodec::decodeJson($json) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $parsed = self::parsePricedLine($item, $currency);
            if ($parsed === null) {
                continue;
            }
            $result[] = new NegotiatedQuoteLineView(
                lineId: $parsed['line_id'],
                sellable: $parsed['sellable'],
                quantity: $parsed['quantity'],
                catalogUnitPrice: $parsed['catalog_unit_price'],
                lockedUnitPrice: $parsed['unit_price'],
                subtotal: $parsed['subtotal'],
                taxTotal: $parsed['tax_total'],
                total: $parsed['total'],
                taxIncluded: $parsed['tax_included'],
                sellingPlan: $parsed['selling_plan'],
            );
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private static function encodeSellingPlan(?SellingPlanSummary $plan): ?array
    {
        if ($plan === null) {
            return null;
        }

        return [
            'code' => $plan->code,
            'billing_kind' => $plan->billingKind->value,
        ];
    }

    /** @param array<array-key, mixed> $data */
    private static function decodeSellingPlan(array $data): ?SellingPlanSummary
    {
        $code = isset($data['code']) && is_string($data['code']) ? $data['code'] : null;
        $kind = isset($data['billing_kind']) && is_string($data['billing_kind']) ? $data['billing_kind'] : null;

        if ($code === null || $kind === null) {
            return null;
        }

        return new SellingPlanSummary($code, SellingPlanBillingKind::from($kind));
    }

    /** @return array<string, mixed> */
    private static function encodeSnapshot(SellableSnapshot $snapshot): array
    {
        return [
            'ref' => (string) $snapshot->ref,
            'sku' => $snapshot->sku,
            'name' => $snapshot->name,
            'kind' => $snapshot->kind->value,
            'requires_shipping' => $snapshot->requiresShipping,
            'purchasable' => $snapshot->purchasable,
            'unavailable_reason' => $snapshot->unavailableReason,
            'options' => $snapshot->options,
            'base_price_minor' => $snapshot->basePrice?->amountMinor,
            'base_price_currency' => $snapshot->basePrice?->currency->code,
            'tax_class' => $snapshot->taxClass,
            'weight_grams' => $snapshot->weightGrams,
            'attributes' => $snapshot->attributes,
            'categories' => $snapshot->categories,
            'step_policy' => $snapshot->quantityStepPolicy === null ? null : [
                'min' => $snapshot->quantityStepPolicy->min,
                'step' => $snapshot->quantityStepPolicy->step,
                'max' => $snapshot->quantityStepPolicy->max,
                'unit' => $snapshot->quantityStepPolicy->unit,
            ],
            'unit_price' => $snapshot->unitPrice === null ? null : [
                'amount_minor' => $snapshot->unitPrice->amount->amountMinor,
                'currency' => $snapshot->unitPrice->amount->currency->code,
                'per_units' => $snapshot->unitPrice->perUnits,
                'unit' => $snapshot->unitPrice->unit,
            ],
            'hs_code' => $snapshot->hsCode,
            'origin_country_code' => $snapshot->originCountryCode,
            'material' => $snapshot->material,
            'customs_description' => $snapshot->customsDescription,
            'seller_ref' => $snapshot->sellerRef === null ? null : (string) $snapshot->sellerRef,
        ];
    }

    /** @param array<array-key, mixed> $data */
    private static function decodeSnapshot(array $data): ?SellableSnapshot
    {
        $ref = isset($data['ref']) && is_string($data['ref']) ? $data['ref'] : null;
        $sku = isset($data['sku']) && is_string($data['sku']) ? $data['sku'] : null;
        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : null;
        $kind = isset($data['kind']) && is_string($data['kind']) ? $data['kind'] : null;
        $requiresShipping = isset($data['requires_shipping']) && is_bool($data['requires_shipping']) ? $data['requires_shipping'] : false;

        if ($ref === null || $sku === null || $name === null || $kind === null) {
            return null;
        }

        $options = [];
        if (isset($data['options']) && is_array($data['options'])) {
            foreach ($data['options'] as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $options[$k] = $v;
                }
            }
        }

        $attributes = [];
        if (isset($data['attributes']) && is_array($data['attributes'])) {
            foreach ($data['attributes'] as $k => $v) {
                if (is_string($k) && is_scalar($v)) {
                    $attributes[$k] = $v;
                }
            }
        }

        $categories = [];
        if (isset($data['categories']) && is_array($data['categories'])) {
            foreach ($data['categories'] as $cat) {
                if (is_string($cat)) {
                    $categories[] = $cat;
                }
            }
        }

        $baseMinor = isset($data['base_price_minor']) && is_int($data['base_price_minor']) ? $data['base_price_minor'] : null;
        $baseCurrency = isset($data['base_price_currency']) && is_string($data['base_price_currency']) ? $data['base_price_currency'] : null;

        $stepPolicy = null;
        if (isset($data['step_policy']) && is_array($data['step_policy'])) {
            $sp = $data['step_policy'];
            if (isset($sp['min'], $sp['step'], $sp['unit']) && is_int($sp['min']) && is_int($sp['step']) && is_string($sp['unit'])) {
                $max = isset($sp['max']) && is_int($sp['max']) ? $sp['max'] : null;
                $stepPolicy = new QuantityStepPolicy($sp['min'], $sp['step'], $max, $sp['unit']);
            }
        }

        $unitPrice = null;
        if (isset($data['unit_price']) && is_array($data['unit_price'])) {
            $up = $data['unit_price'];
            if (isset($up['amount_minor'], $up['currency'], $up['per_units'], $up['unit'])
                && is_int($up['amount_minor'])
                && is_string($up['currency'])
                && is_int($up['per_units'])
                && is_string($up['unit'])) {
                $unitPrice = new UnitPrice(Money::ofMinor($up['amount_minor'], $up['currency']), $up['per_units'], $up['unit']);
            }
        }

        return new SellableSnapshot(
            ref: SellableRef::fromString($ref),
            sku: $sku,
            name: $name,
            kind: SellableKind::from($kind),
            requiresShipping: $requiresShipping,
            purchasable: isset($data['purchasable']) && is_bool($data['purchasable']) ? $data['purchasable'] : true,
            unavailableReason: isset($data['unavailable_reason']) && is_string($data['unavailable_reason']) ? $data['unavailable_reason'] : null,
            options: $options,
            basePrice: $baseMinor !== null && $baseCurrency !== null ? Money::ofMinor($baseMinor, $baseCurrency) : null,
            taxClass: isset($data['tax_class']) && is_string($data['tax_class']) ? $data['tax_class'] : null,
            weightGrams: isset($data['weight_grams']) && is_int($data['weight_grams']) ? $data['weight_grams'] : null,
            attributes: $attributes,
            categories: $categories,
            quantityStepPolicy: $stepPolicy,
            unitPrice: $unitPrice,
            hsCode: isset($data['hs_code']) && is_string($data['hs_code']) ? $data['hs_code'] : null,
            originCountryCode: isset($data['origin_country_code']) && is_string($data['origin_country_code']) ? $data['origin_country_code'] : null,
            material: isset($data['material']) && is_string($data['material']) ? $data['material'] : null,
            customsDescription: isset($data['customs_description']) && is_string($data['customs_description']) ? $data['customs_description'] : null,
            sellerRef: isset($data['seller_ref']) && is_string($data['seller_ref']) ? SellerRef::fromString($data['seller_ref']) : null,
        );
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return array{
     *   line_id: string,
     *   sellable: SellableSnapshot,
     *   quantity: int,
     *   catalog_unit_price: Money,
     *   unit_price: Money,
     *   subtotal: Money,
     *   tax_total: Money,
     *   total: Money,
     *   tax_included: bool,
     *   selling_plan: ?SellingPlanSummary
     * }|null
     */
    private static function parsePricedLine(array $item, Currency $currency): ?array
    {
        $lineId = isset($item['line_id']) && is_string($item['line_id']) ? $item['line_id'] : null;
        $sellableRaw = isset($item['sellable']) && is_array($item['sellable']) ? $item['sellable'] : null;
        $qty = isset($item['quantity']) && is_int($item['quantity']) ? $item['quantity'] : null;
        $catalogMinor = isset($item['catalog_unit_price_minor']) && is_int($item['catalog_unit_price_minor']) ? $item['catalog_unit_price_minor'] : null;
        $unitMinor = isset($item['unit_price_minor']) && is_int($item['unit_price_minor']) ? $item['unit_price_minor'] : null;
        $subMinor = isset($item['subtotal_minor']) && is_int($item['subtotal_minor']) ? $item['subtotal_minor'] : null;
        $taxMinor = isset($item['tax_total_minor']) && is_int($item['tax_total_minor']) ? $item['tax_total_minor'] : null;
        $totMinor = isset($item['total_minor']) && is_int($item['total_minor']) ? $item['total_minor'] : null;
        $taxInc = isset($item['tax_included']) && is_bool($item['tax_included']) ? $item['tax_included'] : false;

        if ($lineId === null || $sellableRaw === null || $qty === null || $catalogMinor === null || $unitMinor === null || $subMinor === null || $taxMinor === null || $totMinor === null) {
            return null;
        }

        $snapshot = self::decodeSnapshot($sellableRaw);
        if ($snapshot === null) {
            return null;
        }

        $plan = isset($item['selling_plan']) && is_array($item['selling_plan'])
            ? self::decodeSellingPlan($item['selling_plan'])
            : null;

        return [
            'line_id' => $lineId,
            'sellable' => $snapshot,
            'quantity' => $qty,
            'catalog_unit_price' => Money::ofMinor($catalogMinor, $currency),
            'unit_price' => Money::ofMinor($unitMinor, $currency),
            'subtotal' => Money::ofMinor($subMinor, $currency),
            'tax_total' => Money::ofMinor($taxMinor, $currency),
            'total' => Money::ofMinor($totMinor, $currency),
            'tax_included' => $taxInc,
            'selling_plan' => $plan,
        ];
    }
}
