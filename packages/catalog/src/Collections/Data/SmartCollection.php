<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Data;

use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\ListProducts;
use Commerz\Catalog\Products\Data\VariantFilter;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Ref;
use Commerz\Measures\Measure;
use DateTimeInterface;
use Packman\Attributes\Api;
use ValueError;

/**
 * Dynamic collection specification backed by serialized {@see AttributeFilter} and {@see VariantFilter} conditions.
 * Every filter holds on one and the same active variant (CAT-03).
 */
#[Api(since: '0.1')]
final readonly class SmartCollection
{
    public const int VERSION = 1;

    /** @var non-empty-list<AttributeFilter|VariantFilter> */
    public array $filters;

    /**
     * @param  array<array-key, mixed>  $filters  a non-empty list of AttributeFilter|VariantFilter
     */
    public function __construct(array $filters)
    {
        if ($filters === [] || ! array_is_list($filters) || count($filters) > ListProducts::MAX_FILTERS) {
            throw new InvalidCommand(
                'filters',
                'a smart collection requires a list of 1 to '.ListProducts::MAX_FILTERS.' filters',
                'catalog.invalid_filter',
            );
        }

        $validated = [];

        foreach ($filters as $filter) {
            if (! $filter instanceof AttributeFilter && ! $filter instanceof VariantFilter) {
                throw new InvalidCommand(
                    'filters',
                    'every smart collection rule must be an AttributeFilter or a VariantFilter',
                    'catalog.invalid_filter',
                );
            }

            $validated[] = $filter;
        }

        $this->filters = $validated;
    }

    public static function of(AttributeFilter|VariantFilter ...$filters): self
    {
        return new self(array_values($filters));
    }

    /**
     * Serializes the filter list into a canonical JSON-encodable rule array.
     *
     * @return non-empty-list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static function (AttributeFilter|VariantFilter $filter): array {
            if ($filter instanceof VariantFilter) {
                return [
                    'kind' => 'variant',
                    'axes' => $filter->axes,
                ];
            }

            return [
                'kind' => 'attribute',
                'attribute' => $filter->attribute,
                'operator' => $filter->operator->value,
                'values' => array_map(self::encodeValue(...), $filter->values),
            ];
        }, $this->filters);
    }

    /**
     * Restores a {@see SmartCollection} from its serialized rule array.
     *
     * @param  array<array-key, mixed>  $rules
     */
    public static function fromArray(array $rules): self
    {
        if ($rules === [] || ! array_is_list($rules)) {
            throw new InvalidCommand('rules', 'smart collection rules must be a non-empty list', 'catalog.invalid_filter');
        }

        $filters = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                throw new InvalidCommand('rules', 'each smart collection rule must be an object', 'catalog.invalid_filter');
            }

            $kind = $rule['kind'] ?? null;

            if ($kind === 'variant') {
                $axes = $rule['axes'] ?? null;

                if (! is_array($axes)) {
                    throw new InvalidCommand('rules', 'variant filter rule requires an axes map', 'catalog.invalid_filter');
                }

                $filters[] = VariantFilter::axes($axes);

                continue;
            }

            if ($kind === 'attribute') {
                $attribute = $rule['attribute'] ?? null;
                $operatorRaw = $rule['operator'] ?? null;
                $values = $rule['values'] ?? null;

                if (! is_string($attribute) || ! is_string($operatorRaw) || ! is_array($values) || ! array_is_list($values)) {
                    throw new InvalidCommand('rules', 'attribute filter rule is malformed', 'catalog.invalid_filter');
                }

                try {
                    $operator = FilterOperator::from($operatorRaw);
                } catch (ValueError) {
                    throw new InvalidCommand('rules', "unknown filter operator '{$operatorRaw}'", 'catalog.invalid_filter');
                }

                $filters[] = match ($operator) {
                    FilterOperator::Equals => count($values) === 1
                        ? AttributeFilter::equals($attribute, $values[0])
                        : throw new InvalidCommand('rules', 'equals filter rule requires one value', 'catalog.invalid_filter'),
                    FilterOperator::In => AttributeFilter::in($attribute, $values),
                    FilterOperator::Range => count($values) === 2
                        ? AttributeFilter::between($attribute, $values[0], $values[1])
                        : throw new InvalidCommand('rules', 'range filter rule requires two bounds', 'catalog.invalid_filter'),
                };

                continue;
            }

            throw new InvalidCommand('rules', 'unknown smart collection rule kind', 'catalog.invalid_filter');
        }

        return new self($filters);
    }

    private static function encodeValue(mixed $value): mixed
    {
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }

        if ($value instanceof Measure) {
            return ['unit' => $value->unit, 'value' => $value->value];
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value instanceof Ref) {
            return (string) $value;
        }

        if (is_array($value)) {
            $encoded = [];

            foreach ($value as $key => $item) {
                $encoded[$key] = self::encodeValue($item);
            }

            return $encoded;
        }

        throw new InvalidCommand('filters', 'filter value is not serializable for a smart collection', 'catalog.invalid_filter');
    }
}
