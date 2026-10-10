<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Errors\CommerzException;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Quotes\Enums\AdjustmentTarget;
use JsonException;
use LogicException;

/**
 * The only (de)serialization of the sales JSONB columns. The package is their only writer, so a stored value that
 * does not decode is a defect and fails with `LogicException`. A money amount of a document is stored as `*_minor`;
 * its currency is the one of the owning row. `lineId` of an adjustment or a tax is not stored: the line that owns the
 * list supplies it (the order line id equals the cart line id the quote was made for).
 */
final class SalesCodec
{
    public static function addressToJson(Address $address): string
    {
        return self::encode([
            'country' => $address->country,
            'region' => $address->region,
            'city' => $address->city,
            'postal_code' => $address->postalCode,
            'lines' => $address->lines,
            'recipient' => $address->recipient,
            'phone' => $address->phone,
        ]);
    }

    public static function addressFromJson(string $json): Address
    {
        return self::guard('address', static function () use ($json): Address {
            $data = self::object($json);
            $lines = $data['lines'] ?? [];

            return new Address(
                self::string($data, 'country'),
                self::nullableString($data, 'region'),
                self::nullableString($data, 'city'),
                self::nullableString($data, 'postal_code'),
                self::stringList($lines),
                self::nullableString($data, 'recipient'),
                self::nullableString($data, 'phone'),
            );
        });
    }

    public static function moneyToJson(Money $money): string
    {
        return self::encode(['amount_minor' => $money->amountMinor, 'currency' => $money->currency->code]);
    }

    public static function moneyFromJson(string $json): Money
    {
        return self::guard('money', static function () use ($json): Money {
            $data = self::object($json);

            return Money::ofMinor(self::int($data, 'amount_minor'), self::string($data, 'currency'));
        });
    }

    /** @param  list<Adjustment>  $adjustments */
    public static function adjustmentsToJson(array $adjustments): string
    {
        return self::encode(array_map(static fn (Adjustment $a): array => [
            'target' => $a->target->value,
            'amount_minor' => $a->amount->amountMinor,
            'source' => $a->source,
            'explanation' => $a->explanation,
            'promotion_id' => $a->promotionId,
            'coupon_code' => $a->couponCode,
        ], $adjustments));
    }

    /**
     * @param  string|null  $lineId  the order line that owns the list; null for the order-level and delivery lists.
     *                               A `line` adjustment and a share of an `order` one take it; a declared `order` one and a `delivery` one have none.
     * @return list<Adjustment>
     */
    public static function adjustmentsFromJson(string $json, Currency $currency, ?string $lineId = null): array
    {
        return self::guard('adjustments', static function () use ($json, $currency, $lineId): array {
            $adjustments = [];

            foreach (self::list($json) as $data) {
                $target = AdjustmentTarget::from(self::string($data, 'target'));

                $adjustments[] = new Adjustment(
                    $target,
                    $target === AdjustmentTarget::Delivery ? null : $lineId,
                    Money::ofMinor(self::int($data, 'amount_minor'), $currency),
                    self::string($data, 'source'),
                    self::string($data, 'explanation'),
                    self::nullableString($data, 'promotion_id'),
                    self::nullableString($data, 'coupon_code'),
                );
            }

            return $adjustments;
        });
    }

    /** @param  list<TaxLine>  $taxLines */
    public static function taxLinesToJson(array $taxLines): string
    {
        return self::encode(array_map(static fn (TaxLine $t): array => [
            'target' => $t->target->value,
            'code' => $t->code,
            'rate_bp' => $t->rate->basisPoints,
            'amount_minor' => $t->amount->amountMinor,
            'included' => $t->included,
        ], $taxLines));
    }

    /**
     * @param  string|null  $lineId  the order line that owns the list; null for the delivery taxes
     * @return list<TaxLine>
     */
    public static function taxLinesFromJson(string $json, Currency $currency, ?string $lineId = null): array
    {
        return self::guard('tax lines', static function () use ($json, $currency, $lineId): array {
            $taxLines = [];

            foreach (self::list($json) as $data) {
                $target = AdjustmentTarget::from(self::string($data, 'target'));
                $included = $data['included'] ?? null;

                $taxLines[] = new TaxLine(
                    $target,
                    $target === AdjustmentTarget::Line ? $lineId : null,
                    self::string($data, 'code'),
                    new Rate(self::int($data, 'rate_bp')),
                    Money::ofMinor(self::int($data, 'amount_minor'), $currency),
                    is_bool($included) ? $included : throw new LogicException("'included' is not a boolean"),
                );
            }

            return $taxLines;
        });
    }

    public static function deliveryToJson(DeliveryCharge $delivery): string
    {
        return self::encode([
            'method_code' => $delivery->methodCode,
            'name' => $delivery->name,
            'carrier' => $delivery->carrier,
            'price_minor' => $delivery->price->amountMinor,
            'tax_included' => $delivery->taxIncluded,
        ]);
    }

    public static function deliveryFromJson(string $json, Currency $currency): DeliveryCharge
    {
        return self::guard('delivery', static function () use ($json, $currency): DeliveryCharge {
            $data = self::object($json);
            $included = $data['tax_included'] ?? null;

            return new DeliveryCharge(
                self::string($data, 'method_code'),
                self::string($data, 'name'),
                self::string($data, 'carrier'),
                Money::ofMinor(self::int($data, 'price_minor'), $currency),
                is_bool($included) ? $included : throw new LogicException("'tax_included' is not a boolean"),
            );
        });
    }

    /** @param  array<string, string>  $options  display label => value */
    public static function optionsToJson(array $options): string
    {
        return self::encode((object) $options);
    }

    /** @return array<string, string> */
    public static function optionsFromJson(string $json): array
    {
        return self::guard('options', static function () use ($json): array {
            $options = [];

            foreach (self::object($json) as $label => $value) {
                $options[(string) $label] = is_string($value) ? $value : throw new LogicException("option '{$label}' is not a string");
            }

            return $options;
        });
    }

    /** @param  list<string>  $values */
    public static function stringsToJson(array $values): string
    {
        return self::encode($values);
    }

    /** @return list<string> */
    public static function stringsFromJson(string $json): array
    {
        return self::guard('string list', static function () use ($json): array {
            $decoded = self::decode($json);

            return self::stringList($decoded);
        });
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decode(string $json): mixed
    {
        try {
            return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('not valid JSON', previous: $e);
        }
    }

    /** @return array<array-key, mixed> */
    private static function object(string $json): array
    {
        $decoded = self::decode($json);

        return is_array($decoded) ? $decoded : throw new LogicException('not a JSON object');
    }

    /** @return list<array<array-key, mixed>> */
    private static function list(string $json): array
    {
        $decoded = self::decode($json);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new LogicException('not a JSON list');
        }

        $items = [];

        foreach ($decoded as $item) {
            $items[] = is_array($item) ? $item : throw new LogicException('a list item is not an object');
        }

        return $items;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new LogicException('not a JSON list');
        }

        $strings = [];

        foreach ($value as $item) {
            $strings[] = is_string($item) ? $item : throw new LogicException('a list item is not a string');
        }

        return $strings;
    }

    /** @param  array<array-key, mixed>  $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : throw new LogicException("'{$key}' is missing or not a string");
    }

    /** @param  array<array-key, mixed>  $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            default => throw new LogicException("'{$key}' is not a string"),
        };
    }

    /** @param  array<array-key, mixed>  $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : throw new LogicException("'{$key}' is missing or not an integer");
    }

    /**
     * @template T
     *
     * @param  callable(): T  $decode
     * @return T
     */
    private static function guard(string $what, callable $decode): mixed
    {
        try {
            return $decode();
        } catch (LogicException $e) {
            throw new LogicException("Stored {$what} is corrupt: {$e->getMessage()}.", previous: $e);
        } catch (CommerzException|\ValueError $e) {
            throw new LogicException("Stored {$what} is corrupt: {$e->getMessage()}", previous: $e);
        }
    }
}
