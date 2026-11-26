<?php

declare(strict_types=1);

namespace Commerz\B2b\Internal;

use Commerz\Ports\Delivery\Data\Address;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Packman\Attributes\Internal;
use RuntimeException;

#[Internal]
final class B2bCodec
{
    public static function encodeJson(mixed $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to encode B2B JSON column.', previous: $e);
        }
    }

    /** @return array<array-key, mixed> */
    public static function decodeJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to decode B2B JSON column.', previous: $e);
        }

        return is_array($decoded) ? $decoded : throw new RuntimeException('Expected JSON array/object.');
    }

    /** @param list<string> $values */
    public static function encodeStrings(array $values): string
    {
        return self::encodeJson($values);
    }

    /** @return list<string> */
    public static function decodeStrings(?string $json): array
    {
        $items = [];

        foreach (self::decodeJson($json) as $value) {
            if (is_string($value)) {
                $items[] = $value;
            }
        }

        return $items;
    }

    public static function encodeAddress(?Address $address): ?string
    {
        if ($address === null) {
            return null;
        }

        return self::encodeJson([
            'country' => $address->country,
            'region' => $address->region,
            'city' => $address->city,
            'postal_code' => $address->postalCode,
            'lines' => $address->lines,
            'recipient' => $address->recipient,
            'phone' => $address->phone,
        ]);
    }

    public static function decodeAddress(?string $json): ?Address
    {
        if ($json === null || $json === '') {
            return null;
        }

        $data = self::decodeJson($json);
        $country = isset($data['country']) && is_string($data['country']) ? $data['country'] : null;

        if ($country === null) {
            return null;
        }

        $lines = [];
        if (isset($data['lines']) && is_array($data['lines'])) {
            foreach ($data['lines'] as $line) {
                if (is_string($line)) {
                    $lines[] = $line;
                }
            }
        }

        return new Address(
            country: $country,
            region: isset($data['region']) && is_string($data['region']) ? $data['region'] : null,
            city: isset($data['city']) && is_string($data['city']) ? $data['city'] : null,
            postalCode: isset($data['postal_code']) && is_string($data['postal_code']) ? $data['postal_code'] : null,
            lines: $lines,
            recipient: isset($data['recipient']) && is_string($data['recipient']) ? $data['recipient'] : null,
            phone: isset($data['phone']) && is_string($data['phone']) ? $data['phone'] : null,
        );
    }

    public static function parseDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value)->setTimezone(new DateTimeZone('UTC'));
    }

    public static function parseNullableDate(?string $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : self::parseDate($value);
    }
}
