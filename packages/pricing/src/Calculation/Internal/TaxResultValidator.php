<?php

declare(strict_types=1);

namespace Commerz\Pricing\Calculation\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Pricing\Calculation\Data\TaxableItem;
use Commerz\Pricing\Calculation\Data\TaxRequest;
use Commerz\Pricing\Calculation\Data\TaxResult;
use Commerz\Pricing\Calculation\Exceptions\InvalidTaxResult;
use Packman\Attributes\Internal;

/** Checks an answer of a `tax_calculator` against the request it was given; a calculator is never trusted (EXT-01). */
#[Internal]
final class TaxResultValidator
{
    /** @throws InvalidTaxResult */
    public function assertValid(string $calculator, TaxRequest $request, TaxResult $result): void
    {
        $items = [];

        foreach ($request->items as $item) {
            $items[$item->key()] = $item;
        }

        $seen = [];
        $sums = [];

        foreach ($result->amounts as $amount) {
            $item = $items[$amount->itemKey()] ?? null;

            if (! $item instanceof TaxableItem) {
                throw new InvalidTaxResult($calculator, "tax '{$amount->code}' refers to unknown item {$amount->itemKey()}");
            }

            if (! $amount->amount->currency->equals($request->context->currency)) {
                throw new InvalidTaxResult($calculator, "tax '{$amount->code}' of item {$item->key()} is in {$amount->amount->currency->code}, expected {$request->context->currency->code}");
            }

            $id = $item->key().'|'.$amount->code;

            if (isset($seen[$id])) {
                throw new InvalidTaxResult($calculator, "tax '{$amount->code}' of item {$item->key()} appears twice");
            }

            $seen[$id] = true;

            try {
                $sums[$item->key()] = ($sums[$item->key()] ?? Money::zero($request->context->currency))->plus($amount->amount);
            } catch (InvalidCommand $e) {
                throw new InvalidTaxResult($calculator, "taxes of item {$item->key()} overflow: {$e->reason}");
            }
        }

        foreach ($sums as $key => $sum) {
            $item = $items[$key];

            if ($item->included && $sum->compare($item->base) > 0) {
                throw new InvalidTaxResult($calculator, "included taxes of item {$key} exceed its base");
            }
        }
    }
}
