<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Data;

use BackedEnum;
use Commerz\B2b\PaymentTerms\Enums\SellingPlanBillingKind;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;
use ReflectionObject;
use UnitEnum;

/**
 * Transport- and package-independent descriptor of a line's SellingPlan billing cadence, supporting both explicit B2B
 * descriptors and core `SellingPlan` value objects.
 */
#[Api(since: '0.1')]
final readonly class SellingPlanSummary
{
    public string $code;

    public function __construct(
        string $code,
        public SellingPlanBillingKind $billingKind,
    ) {
        $trimmed = trim($code);

        if ($trimmed === '' || strlen($trimmed) > 120) {
            throw new InvalidCommand('code', 'selling plan code must be 1..120 bytes', 'b2b.invalid_selling_plan');
        }

        $this->code = $trimmed;
    }

    public static function oneTime(string $code = 'one_time'): self
    {
        return new self($code, SellingPlanBillingKind::OneTime);
    }

    public static function recurring(string $code = 'recurring'): self
    {
        return new self($code, SellingPlanBillingKind::Recurring);
    }

    public static function deposit(string $code = 'deposit'): self
    {
        return new self($code, SellingPlanBillingKind::Deposit);
    }

    public static function deferred(string $code = 'deferred'): self
    {
        return new self($code, SellingPlanBillingKind::Deferred);
    }

    public static function from(object $plan): self
    {
        if ($plan instanceof self) {
            return $plan;
        }

        $reflection = new ReflectionObject($plan);
        $code = 'selling_plan';

        foreach (['code', 'id', 'key', 'name'] as $candidateProp) {
            if ($reflection->hasProperty($candidateProp)) {
                $prop = $reflection->getProperty($candidateProp);
                if ($prop->isPublic()) {
                    $raw = $prop->getValue($plan);
                    if (is_string($raw) && trim($raw) !== '') {
                        $code = trim($raw);
                        break;
                    }
                    if (is_object($raw) && method_exists($raw, '__toString')) {
                        $str = trim((string) $raw);
                        if ($str !== '') {
                            $code = $str;
                            break;
                        }
                    }
                }
            }
        }

        if ($reflection->hasMethod('isRecurring')) {
            $method = $reflection->getMethod('isRecurring');
            if ($method->isPublic() && $method->getNumberOfRequiredParameters() === 0) {
                $result = $method->invoke($plan);
                if ($result === true) {
                    return new self($code, SellingPlanBillingKind::Recurring);
                }
            }
        }

        foreach (['billingPolicy', 'billingKind', 'policy'] as $policyProp) {
            if (! $reflection->hasProperty($policyProp)) {
                continue;
            }
            $prop = $reflection->getProperty($policyProp);
            if (! $prop->isPublic()) {
                continue;
            }
            $value = $prop->getValue($plan);
            $resolved = self::resolveBillingKind($value);
            if ($resolved !== null) {
                return new self($code, $resolved);
            }
        }

        return new self($code, SellingPlanBillingKind::OneTime);
    }

    /** @param array<string, scalar> $attributes */
    public static function fromSellableAttributes(array $attributes): ?self
    {
        foreach (['selling_plan_billing_policy', 'billing_policy', 'selling_plan'] as $key) {
            if (! isset($attributes[$key]) || ! is_string($attributes[$key])) {
                continue;
            }

            $normalized = strtolower(trim($attributes[$key]));
            $code = isset($attributes['selling_plan_code']) && is_string($attributes['selling_plan_code']) && trim($attributes['selling_plan_code']) !== ''
                ? trim($attributes['selling_plan_code'])
                : $normalized;

            if ($normalized === 'recurring') {
                return new self($code, SellingPlanBillingKind::Recurring);
            }

            if ($normalized === 'deposit') {
                return new self($code, SellingPlanBillingKind::Deposit);
            }

            if ($normalized === 'deferred') {
                return new self($code, SellingPlanBillingKind::Deferred);
            }
        }

        return null;
    }

    public function isRecurring(): bool
    {
        return $this->billingKind->isRecurring();
    }

    private static function resolveBillingKind(mixed $value): ?SellingPlanBillingKind
    {
        if ($value instanceof SellingPlanBillingKind) {
            return $value;
        }

        if ($value instanceof BackedEnum && is_string($value->value)) {
            return SellingPlanBillingKind::tryFrom(strtolower($value->value));
        }

        if ($value instanceof UnitEnum) {
            return SellingPlanBillingKind::tryFrom(strtolower($value->name));
        }

        if (is_string($value)) {
            return SellingPlanBillingKind::tryFrom(strtolower(trim($value)));
        }

        if (is_object($value)) {
            $ref = new ReflectionObject($value);
            foreach (['type', 'kind', 'mode', 'policy'] as $nested) {
                if ($ref->hasProperty($nested) && $ref->getProperty($nested)->isPublic()) {
                    $found = self::resolveBillingKind($ref->getProperty($nested)->getValue($value));
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }
}
