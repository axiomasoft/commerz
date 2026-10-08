<?php

declare(strict_types=1);

namespace Commerz\Stores\Internal;

use Commerz\Money\Currency;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\ChannelView;
use Commerz\Stores\Data\StoreId;
use Commerz\Stores\Data\StoreView;
use Commerz\Stores\Enums\ChannelStatus;
use Commerz\Stores\Enums\StoreStatus;
use DateTimeImmutable;
use JsonException;
use RuntimeException;

/** Maps raw `stores` / `channels` rows to view DTOs. */
final class StoreRows
{
    public static function store(object $row): StoreView
    {
        return new StoreView(
            new StoreId(self::string($row, 'id')),
            self::string($row, 'code'),
            self::string($row, 'name'),
            Currency::of(self::string($row, 'default_currency')),
            self::string($row, 'order_number_prefix'),
            StoreStatus::from(self::string($row, 'status')),
            new DateTimeImmutable(self::string($row, 'created_at')),
            new DateTimeImmutable(self::string($row, 'updated_at')),
        );
    }

    public static function channel(object $row): ChannelView
    {
        return new ChannelView(
            new ChannelId(self::string($row, 'id')),
            new StoreId(self::string($row, 'store_id')),
            self::string($row, 'code'),
            self::string($row, 'name'),
            array_map(Currency::of(...), self::strings($row, 'currencies')),
            Currency::of(self::string($row, 'default_currency')),
            self::strings($row, 'locales'),
            self::string($row, 'default_locale'),
            (bool) (get_object_vars($row)['prices_include_tax'] ?? false),
            ChannelStatus::from(self::string($row, 'status')),
            new DateTimeImmutable(self::string($row, 'created_at')),
            new DateTimeImmutable(self::string($row, 'updated_at')),
        );
    }

    private static function string(object $row, string $column): string
    {
        $value = get_object_vars($row)[$column] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("Column '{$column}' is missing or not a string.");
        }

        return $value;
    }

    /** @return list<string> */
    private static function strings(object $row, string $column): array
    {
        try {
            $decoded = json_decode(self::string($row, $column), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Column '{$column}' is not valid JSON.", previous: $e);
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException("Column '{$column}' is not a JSON list.");
        }

        return array_map(static fn (mixed $item): string => is_string($item) ? $item : throw new RuntimeException("Column '{$column}' holds a non-string."), $decoded);
    }
}
