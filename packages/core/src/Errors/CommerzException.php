<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;

/** Root of Commerz domain errors, with Packman descriptors and stable domain code/context metadata. */
#[Api(since: '0.1')]
abstract class CommerzException extends CodedException
{
    private const int DEFAULT_MAX_DETAIL_CHARS = 120;

    abstract public function code(): string;

    public function category(): ErrorCategory
    {
        return $this->errorDescriptor()->category;
    }

    /** @return array<string, scalar|null> */
    abstract public function context(): array;

    /** @return array<string, scalar|list<scalar|null>|null> */
    protected function descriptorDetails(): array
    {
        return self::sanitizeDetails($this->context());
    }

    /**
     * Sanitizes and bounds structured detail fields so untrusted control characters, invalid UTF-8, or oversized inputs
     * never break {@see ErrorDescriptor} construction while preserving raw exception properties and messages.
     *
     * @param  array<array-key, mixed>  $details
     * @return array<string, scalar|list<scalar|null>|null>
     */
    protected static function sanitizeDetails(array $details, int $maxStringLength = self::DEFAULT_MAX_DETAIL_CHARS): array
    {
        $limit = max(0, $maxStringLength);

        while (true) {
            $normalized = self::normalizeDetailMap($details, $limit);
            $encoded = json_encode($normalized, JSON_THROW_ON_ERROR);

            if (strlen($encoded) <= ErrorDescriptor::MAX_DETAIL_BYTES || $limit === 0) {
                return $normalized;
            }

            $limit = intdiv($limit, 2);
        }
    }

    protected static function sanitizeDetailString(string $value, int $maxLength = self::DEFAULT_MAX_DETAIL_CHARS): string
    {
        $scrubbed = mb_scrub($value, 'UTF-8');
        $clean = preg_replace('/[\t\r\n]+/', ' ', $scrubbed) ?? '';
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $clean) ?? '';
        $limit = max(0, $maxLength);

        if ($limit === 0) {
            return '';
        }

        return mb_substr($clean, 0, $limit, 'UTF-8');
    }

    /**
     * @param  array<array-key, mixed>  $details
     * @return array<string, scalar|list<scalar|null>|null>
     */
    private static function normalizeDetailMap(array $details, int $maxStringLength): array
    {
        $normalized = [];

        foreach ($details as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $cleanKey = self::sanitizeDetailString($key, 64);

            if ($cleanKey === '') {
                continue;
            }

            $normalized[$cleanKey] = self::normalizeDetailValue($value, $maxStringLength);
        }

        return $normalized;
    }

    /** @return scalar|list<scalar|null>|null */
    private static function normalizeDetailValue(mixed $value, int $maxStringLength): string|int|float|bool|array|null
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (is_string($value)) {
            return self::sanitizeDetailString($value, $maxStringLength);
        }

        if (is_array($value) && array_is_list($value)) {
            $list = [];

            foreach ($value as $item) {
                if (is_array($item)) {
                    continue;
                }

                /** @var scalar|null $scalar */
                $scalar = self::normalizeDetailValue($item, $maxStringLength);
                $list[] = $scalar;
            }

            return $list;
        }

        return null;
    }
}
