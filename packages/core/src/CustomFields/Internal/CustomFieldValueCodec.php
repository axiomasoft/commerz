<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Internal;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\RoundingNecessaryException;
use Commerz\CustomFields\Data\CustomFieldDefinition;
use Commerz\CustomFields\Data\CustomFieldIndexFact;
use Commerz\CustomFields\Enums\CustomFieldType;
use Commerz\CustomFields\Exceptions\InvalidCustomFieldValue;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Commerz\Metadata\Exceptions\InvalidMetadata;
use Commerz\Metadata\MetadataValidator;
use DateTimeInterface;
use Packman\Attributes\Internal;

/**
 * Validates custom field rules, normalizes raw values per `CustomFieldType`, and extracts `cmz_custom_field_index` facts.
 */
#[Internal]
final class CustomFieldValueCodec
{
    private const string ENTITY_REF_PATTERN = '/\A[a-z0-9_.\-]{1,64}:[^\p{Cc}]{1,120}\z/u';

    /**
     * Validates `validationRules` at definition construction time and returns canonicalized rules.
     *
     * @param  array<array-key, mixed>  $rules
     * @return array<array-key, mixed>
     */
    public static function validateRules(CustomFieldType $type, array $rules): array
    {
        $parsed = self::extractConstraints($rules, isDefinitionCheck: true);

        if (($type === CustomFieldType::Enum || $type === CustomFieldType::MultiEnum) && $parsed['choices'] !== null && $parsed['choices'] === []) {
            throw new InvalidCommand('validationRules', 'choices must be a non-empty list of strings', 'custom_fields.invalid_definition');
        }

        if ($parsed['minLength'] !== null && $parsed['maxLength'] !== null && $parsed['minLength'] > $parsed['maxLength']) {
            throw new InvalidCommand('validationRules', 'min_length must not exceed max_length', 'custom_fields.invalid_definition');
        }

        if ($type === CustomFieldType::Integer && $parsed['minInt'] !== null && $parsed['maxInt'] !== null && $parsed['minInt'] > $parsed['maxInt']) {
            throw new InvalidCommand('validationRules', 'min must not exceed max', 'custom_fields.invalid_definition');
        }

        if ($type === CustomFieldType::Decimal && $parsed['minDecimal'] !== null && $parsed['maxDecimal'] !== null) {
            if ($parsed['minDecimal']->isGreaterThan($parsed['maxDecimal'])) {
                throw new InvalidCommand('validationRules', 'min must not exceed max', 'custom_fields.invalid_definition');
            }
        }

        return $rules;
    }

    public static function normalize(mixed $input, CustomFieldDefinition $definition): mixed
    {
        $field = $definition->qualifiedKey();
        $constraints = self::extractConstraints($definition->validationRules, isDefinitionCheck: false);

        return match ($definition->type) {
            CustomFieldType::String => self::normalizeString($input, $field, $constraints),
            CustomFieldType::Integer => self::normalizeInteger($input, $field, $constraints),
            CustomFieldType::Decimal => self::normalizeDecimal($input, $field, $constraints),
            CustomFieldType::Boolean => self::normalizeBoolean($input, $field),
            CustomFieldType::Date => self::normalizeDate($input, $field, $constraints),
            CustomFieldType::Enum => self::normalizeEnum($input, $field, $constraints),
            CustomFieldType::MultiEnum => self::normalizeMultiEnum($input, $field, $constraints),
            CustomFieldType::LocalizedString => self::normalizeLocalizedString($input, $field),
            CustomFieldType::EntityRef => self::normalizeEntityRef($input, $field, $constraints),
            CustomFieldType::Json => self::normalizeJson($input, $field),
        };
    }

    /**
     * Computes `cmz_custom_field_index` facts from an already-normalized value.
     *
     * @return list<CustomFieldIndexFact>
     */
    public static function indexFacts(mixed $normalizedValue, CustomFieldDefinition $definition): array
    {
        if (! $definition->isFilterable) {
            return [];
        }

        return match ($definition->type) {
            CustomFieldType::String,
            CustomFieldType::Enum,
            CustomFieldType::EntityRef => is_string($normalizedValue)
                ? [CustomFieldIndexFact::term($normalizedValue)]
                : [],
            CustomFieldType::MultiEnum => is_array($normalizedValue)
                ? self::multiEnumFacts($normalizedValue)
                : [],
            CustomFieldType::Integer => is_int($normalizedValue)
                ? [CustomFieldIndexFact::number((string) $normalizedValue)]
                : [],
            CustomFieldType::Decimal => is_string($normalizedValue)
                ? [CustomFieldIndexFact::number($normalizedValue)]
                : [],
            CustomFieldType::Boolean => is_bool($normalizedValue)
                ? [CustomFieldIndexFact::flag($normalizedValue)]
                : [],
            CustomFieldType::Date => is_string($normalizedValue)
                ? [CustomFieldIndexFact::day($normalizedValue)]
                : [],
            CustomFieldType::LocalizedString,
            CustomFieldType::Json => [],
        };
    }

    /**
     * Normalizes a single filter value for a definition (allowing a single choice string on `MultiEnum`).
     */
    public static function normalizeFilterSingleValue(mixed $input, CustomFieldDefinition $definition): CustomFieldIndexFact
    {
        if ($definition->type === CustomFieldType::MultiEnum && ! is_array($input)) {
            $normalized = self::normalize([$input], $definition);
        } else {
            $normalized = self::normalize($input, $definition);
        }

        $facts = self::indexFacts($normalized, $definition);

        if (count($facts) !== 1) {
            throw new InvalidCustomFieldValue($definition->qualifiedKey(), 'filter predicate requires a single scalar value');
        }

        return $facts[0];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<CustomFieldIndexFact>
     */
    private static function multiEnumFacts(array $values): array
    {
        $facts = [];
        foreach ($values as $item) {
            if (is_string($item)) {
                $facts[] = CustomFieldIndexFact::term($item);
            }
        }

        return $facts;
    }

    /**
     * @param  array{
     *     minLength: ?int,
     *     maxLength: ?int,
     *     pattern: ?string,
     *     choices: ?list<string>
     * }  $constraints
     */
    private static function normalizeString(mixed $input, string $field, array $constraints): string
    {
        if (! is_string($input) || ! mb_check_encoding($input, 'UTF-8')) {
            throw new InvalidCustomFieldValue($field, 'expected a valid UTF-8 string');
        }

        $minLength = $constraints['minLength'];
        $maxLength = $constraints['maxLength'] ?? 10000;
        $length = mb_strlen($input, 'UTF-8');

        if ($minLength !== null && $length < $minLength) {
            throw new InvalidCustomFieldValue($field, "must be at least {$minLength} characters");
        }

        if ($length > $maxLength) {
            throw new InvalidCustomFieldValue($field, "must not exceed {$maxLength} characters");
        }

        if ($constraints['pattern'] !== null && preg_match($constraints['pattern'], $input) !== 1) {
            throw new InvalidCustomFieldValue($field, 'does not match required pattern');
        }

        if ($constraints['choices'] !== null && ! in_array($input, $constraints['choices'], true)) {
            throw new InvalidCustomFieldValue($field, 'is not among the allowed values');
        }

        return $input;
    }

    /**
     * @param  array{minInt: ?int, maxInt: ?int}  $constraints
     */
    private static function normalizeInteger(mixed $input, string $field, array $constraints): int
    {
        if (is_int($input)) {
            $value = $input;
        } elseif (is_string($input) && preg_match('/\A-?\d+\z/', $input) === 1) {
            try {
                $value = BigInteger::of($input)->toInt();
            } catch (MathException) {
                throw new InvalidCustomFieldValue($field, 'integer overflows 64-bit signed range');
            }
        } else {
            throw new InvalidCustomFieldValue($field, 'expected an integer');
        }

        if ($constraints['minInt'] !== null && $value < $constraints['minInt']) {
            throw new InvalidCustomFieldValue($field, "must be at least {$constraints['minInt']}");
        }

        if ($constraints['maxInt'] !== null && $value > $constraints['maxInt']) {
            throw new InvalidCustomFieldValue($field, "must not exceed {$constraints['maxInt']}");
        }

        return $value;
    }

    /**
     * @param  array{scale: int, minDecimal: ?BigDecimal, maxDecimal: ?BigDecimal}  $constraints
     */
    private static function normalizeDecimal(mixed $input, string $field, array $constraints): string
    {
        if (is_int($input)) {
            $input = (string) $input;
        }

        if (! is_string($input) || preg_match('/\A-?\d{1,30}(\.\d{1,30})?\z/', $input) !== 1) {
            throw new InvalidCustomFieldValue($field, 'expected a decimal string or integer (floats are forbidden)');
        }

        $scale = $constraints['scale'];

        try {
            $decimal = BigDecimal::of($input)->toScale(max(0, $scale));
        } catch (RoundingNecessaryException) {
            throw new InvalidCustomFieldValue($field, "exceeds maximum scale of {$scale} decimal places");
        } catch (MathException) {
            throw new InvalidCustomFieldValue($field, 'malformed decimal number');
        }

        if ($constraints['minDecimal'] !== null && $decimal->isLessThan($constraints['minDecimal'])) {
            throw new InvalidCustomFieldValue($field, 'is below minimum allowed decimal value');
        }

        if ($constraints['maxDecimal'] !== null && $decimal->isGreaterThan($constraints['maxDecimal'])) {
            throw new InvalidCustomFieldValue($field, 'exceeds maximum allowed decimal value');
        }

        return (string) $decimal;
    }

    private static function normalizeBoolean(mixed $input, string $field): bool
    {
        if (! is_bool($input)) {
            throw new InvalidCustomFieldValue($field, 'expected a boolean');
        }

        return $input;
    }

    /**
     * @param  array{minDate: ?string, maxDate: ?string}  $constraints
     */
    private static function normalizeDate(mixed $input, string $field, array $constraints): string
    {
        if ($input instanceof DateTimeInterface) {
            $ymd = $input->format('Y-m-d');
        } elseif (is_string($input) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $input, $matches) === 1 && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            $ymd = $input;
        } else {
            throw new InvalidCustomFieldValue($field, 'expected a valid date in Y-m-d format');
        }

        if ($constraints['minDate'] !== null && $ymd < $constraints['minDate']) {
            throw new InvalidCustomFieldValue($field, "date must not be earlier than {$constraints['minDate']}");
        }

        if ($constraints['maxDate'] !== null && $ymd > $constraints['maxDate']) {
            throw new InvalidCustomFieldValue($field, "date must not be later than {$constraints['maxDate']}");
        }

        return $ymd;
    }

    /**
     * @param  array{choices: ?list<string>}  $constraints
     */
    private static function normalizeEnum(mixed $input, string $field, array $constraints): string
    {
        if (! is_string($input) || trim($input) === '' || strlen($input) > 255) {
            throw new InvalidCustomFieldValue($field, 'expected a non-empty enum option string (<= 255 bytes)');
        }

        if ($constraints['choices'] !== null && ! in_array($input, $constraints['choices'], true)) {
            throw new InvalidCustomFieldValue($field, "'{$input}' is not among the allowed enum choices");
        }

        return $input;
    }

    /**
     * @param  array{choices: ?list<string>}  $constraints
     * @return non-empty-list<string>
     */
    private static function normalizeMultiEnum(mixed $input, string $field, array $constraints): array
    {
        if (! is_array($input) || $input === [] || ! array_is_list($input)) {
            throw new InvalidCustomFieldValue($field, 'expected a non-empty list of enum option strings');
        }

        $normalized = [];
        foreach ($input as $item) {
            $choice = self::normalizeEnum($item, $field, $constraints);
            if (! in_array($choice, $normalized, true)) {
                $normalized[] = $choice;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeLocalizedString(mixed $input, string $field): array
    {
        if ($input instanceof LocalizedText) {
            $texts = $input->texts;
        } elseif (is_array($input)) {
            $map = [];
            foreach ($input as $locale => $text) {
                if (! is_string($locale) || ! is_string($text)) {
                    throw new InvalidCustomFieldValue($field, 'localized_string expects a map of locale => non-empty string');
                }
                $map[$locale] = $text;
            }

            try {
                $texts = (new LocalizedText($map))->texts;
            } catch (InvalidCommand $e) {
                throw new InvalidCustomFieldValue($field, $e->reason);
            }
        } else {
            throw new InvalidCustomFieldValue($field, 'expected a LocalizedText instance or locale => text map');
        }

        $result = [];
        foreach ($texts as $locale => $text) {
            $result[(string) $locale] = $text;
        }

        ksort($result);

        return $result;
    }

    /**
     * @param  array{allowedTypes: ?list<string>}  $constraints
     */
    private static function normalizeEntityRef(mixed $input, string $field, array $constraints): string
    {
        $raw = is_object($input) && method_exists($input, '__toString') ? (string) $input : $input;

        if (! is_string($raw) || strlen($raw) > 191 || preg_match(self::ENTITY_REF_PATTERN, $raw) !== 1) {
            throw new InvalidCustomFieldValue($field, 'expected an entity reference formatted as type:id');
        }

        $colonPos = strpos($raw, ':');
        $refType = $colonPos !== false ? substr($raw, 0, $colonPos) : '';

        if ($constraints['allowedTypes'] !== null && ! in_array($refType, $constraints['allowedTypes'], true)) {
            throw new InvalidCustomFieldValue($field, "entity reference type '{$refType}' is not allowed");
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private static function normalizeJson(mixed $input, string $field): array
    {
        if (! is_array($input)) {
            throw new InvalidCustomFieldValue($field, 'json custom field expects an array or map');
        }

        if (array_is_list($input)) {
            try {
                $wrapped = MetadataValidator::validateMap(['items' => $input], $field);
            } catch (InvalidMetadata $e) {
                throw new InvalidCustomFieldValue($field, $e->reason);
            }

            /** @var list<mixed> $items */
            $items = is_array($wrapped['items']) ? $wrapped['items'] : [];

            return $items;
        }

        try {
            return MetadataValidator::validateMap($input, $field);
        } catch (InvalidMetadata $e) {
            throw new InvalidCustomFieldValue($field, $e->reason);
        }
    }

    /**
     * Supports both structured associative rules (`['min' => 0, 'max' => 100, 'choices' => [...]]`) and Laravel-style
     * string rules (`['min:1', 'max:64', 'in:a,b', 'regex:/.../']`).
     *
     * @param  array<array-key, mixed>  $rules
     * @return array{
     *     scale: int,
     *     minLength: ?int,
     *     maxLength: ?int,
     *     minInt: ?int,
     *     maxInt: ?int,
     *     minDecimal: ?BigDecimal,
     *     maxDecimal: ?BigDecimal,
     *     minDate: ?string,
     *     maxDate: ?string,
     *     pattern: ?string,
     *     choices: ?list<string>,
     *     allowedTypes: ?list<string>
     * }
     */
    private static function extractConstraints(array $rules, bool $isDefinitionCheck): array
    {
        $scale = 2;
        $minLength = null;
        $maxLength = null;
        $minRaw = null;
        $maxRaw = null;
        $pattern = null;
        $choices = null;
        $allowedTypes = null;

        foreach ($rules as $ruleKey => $ruleValue) {
            if (is_int($ruleKey) && is_string($ruleValue)) {
                self::applyStringRule($ruleValue, $minRaw, $maxRaw, $minLength, $maxLength, $pattern, $choices, $isDefinitionCheck);

                continue;
            }

            if (! is_string($ruleKey)) {
                if ($isDefinitionCheck) {
                    throw new InvalidCommand('validationRules', 'rule entries must be keyed by rule name or string expressions', 'custom_fields.invalid_definition');
                }

                continue;
            }

            match ($ruleKey) {
                'scale' => $scale = self::parseScale($ruleValue),
                'min_length', 'minLength' => $minLength = self::parseNonNegativeInt($ruleKey, $ruleValue),
                'max_length', 'maxLength' => $maxLength = self::parsePositiveInt($ruleKey, $ruleValue, 10000),
                'min' => $minRaw = $ruleValue,
                'max' => $maxRaw = $ruleValue,
                'pattern', 'regex' => $pattern = self::parsePattern($ruleValue),
                'choices', 'in' => $choices = self::parseStringList($ruleKey, $ruleValue),
                'allowed_types', 'allowedTypes' => $allowedTypes = self::parseStringList($ruleKey, $ruleValue),
                default => $isDefinitionCheck
                    ? throw new InvalidCommand('validationRules', "unknown validation rule '{$ruleKey}'", 'custom_fields.invalid_definition')
                    : null,
            };
        }

        return [
            'scale' => $scale,
            'minLength' => $minLength,
            'maxLength' => $maxLength,
            'minInt' => self::toOptionalInt($minRaw),
            'maxInt' => self::toOptionalInt($maxRaw),
            'minDecimal' => self::toOptionalDecimal($minRaw, $scale, $isDefinitionCheck),
            'maxDecimal' => self::toOptionalDecimal($maxRaw, $scale, $isDefinitionCheck),
            'minDate' => self::toOptionalDate($minRaw),
            'maxDate' => self::toOptionalDate($maxRaw),
            'pattern' => $pattern,
            'choices' => $choices,
            'allowedTypes' => $allowedTypes,
        ];
    }

    /**
     * @param  list<string>|null  $choices
     */
    private static function applyStringRule(
        string $rule,
        mixed &$minRaw,
        mixed &$maxRaw,
        ?int &$minLength,
        ?int &$maxLength,
        ?string &$pattern,
        ?array &$choices,
        bool $isDefinitionCheck,
    ): void {
        if (in_array($rule, ['required', 'nullable', 'string', 'integer', 'boolean', 'date', 'array'], true)) {
            return;
        }

        if (str_starts_with($rule, 'min:')) {
            $bound = substr($rule, 4);
            $minRaw = $bound;
            if (preg_match('/\A\d+\z/', $bound) === 1) {
                $minLength ??= (int) $bound;
            }

            return;
        }

        if (str_starts_with($rule, 'max:')) {
            $bound = substr($rule, 4);
            $maxRaw = $bound;
            if (preg_match('/\A\d+\z/', $bound) === 1) {
                $maxLength ??= (int) $bound;
            }

            return;
        }

        if (str_starts_with($rule, 'in:')) {
            $parts = array_values(array_filter(explode(',', substr($rule, 3)), static fn (string $part): bool => $part !== ''));
            $choices = $parts;

            return;
        }

        if (str_starts_with($rule, 'regex:')) {
            $pattern = self::parsePattern(substr($rule, 6));

            return;
        }

        if ($isDefinitionCheck) {
            throw new InvalidCommand('validationRules', "unsupported validation rule '{$rule}'", 'custom_fields.invalid_definition');
        }
    }

    private static function parseScale(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 12) {
            throw new InvalidCommand('validationRules', 'scale must be an integer between 0 and 12', 'custom_fields.invalid_definition');
        }

        return $value;
    }

    private static function parseNonNegativeInt(string $name, mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 10000) {
            throw new InvalidCommand('validationRules', "{$name} must be an integer between 0 and 10000", 'custom_fields.invalid_definition');
        }

        return $value;
    }

    private static function parsePositiveInt(string $name, mixed $value, int $max): int
    {
        if (! is_int($value) || $value < 1 || $value > $max) {
            throw new InvalidCommand('validationRules', "{$name} must be an integer between 1 and {$max}", 'custom_fields.invalid_definition');
        }

        return $value;
    }

    private static function parsePattern(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidCommand('validationRules', 'pattern must be a valid PCRE regular expression', 'custom_fields.invalid_definition');
        }

        set_error_handler(static fn (): bool => true);

        try {
            $isValid = preg_match($value, '') !== false;
        } finally {
            restore_error_handler();
        }

        if (! $isValid) {
            throw new InvalidCommand('validationRules', 'pattern must be a valid PCRE regular expression', 'custom_fields.invalid_definition');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function parseStringList(string $name, mixed $value): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw new InvalidCommand('validationRules', "{$name} must be a non-empty list of strings", 'custom_fields.invalid_definition');
        }

        $items = [];
        foreach ($value as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw new InvalidCommand('validationRules', "{$name} entries must be non-empty strings", 'custom_fields.invalid_definition');
            }
            $items[] = $entry;
        }

        return $items;
    }

    private static function toOptionalInt(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/\A-?\d+\z/', $raw) === 1) {
            try {
                return BigInteger::of($raw)->toInt();
            } catch (MathException) {
                return null;
            }
        }

        return null;
    }

    private static function toOptionalDecimal(mixed $raw, int $scale, bool $strict): ?BigDecimal
    {
        if ($raw === null) {
            return null;
        }

        if (is_int($raw)) {
            $raw = (string) $raw;
        }

        if (! is_string($raw) || preg_match('/\A-?\d{1,30}(\.\d{1,30})?\z/', $raw) !== 1) {
            return null;
        }

        try {
            return BigDecimal::of($raw)->toScale(max(0, $scale));
        } catch (MathException $e) {
            if ($strict) {
                throw new InvalidCommand('validationRules', 'decimal bound exceeds configured scale: '.$e->getMessage(), 'custom_fields.invalid_definition');
            }

            return null;
        }
    }

    private static function toOptionalDate(mixed $raw): ?string
    {
        if (is_string($raw) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $raw, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $raw;
        }

        return null;
    }
}
