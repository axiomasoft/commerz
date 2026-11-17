<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal\Types;

use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Data\IndexFact;
use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeConfig;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Internal\ConfigReader;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Ref;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ReferenceType implements AttributeType
{
    public function key(): string
    {
        return 'reference';
    }

    public function configVersion(): int
    {
        return 1;
    }

    public function usesChoices(): bool
    {
        return false;
    }

    public function config(array $config): array
    {
        ConfigReader::only($config, ['refType']);

        $type = $config['refType'] ?? null;

        if (! is_string($type) || preg_match('/\A[a-z0-9_.\-]{1,64}\z/', $type) !== 1) {
            throw new InvalidAttributeConfig('refType', 'is required and must match [a-z0-9_.-]{1,64}');
        }

        return ['refType' => $type];
    }

    public function normalize(mixed $input, AttributeDefinition $definition): string
    {
        try {
            $ref = match (true) {
                $input instanceof Ref => ValueRef::of($input->type, $input->id),
                is_string($input) => ValueRef::fromString($input),
                default => throw new InvalidAttributeValue($definition->code, 'expected a reference as type:id'),
            };
        } catch (InvalidCommand $e) {
            throw new InvalidAttributeValue($definition->code, $e->reason);
        }

        if ($ref->type !== ($definition->config['refType'] ?? null)) {
            throw new InvalidAttributeValue($definition->code, 'the reference points to another type');
        }

        return (string) $ref;
    }

    public function index(string|int|bool|array $value, AttributeDefinition $definition): array
    {
        return is_string($value) ? [IndexFact::term($value)] : [];
    }

    public function operators(): array
    {
        return [FilterOperator::Equals, FilterOperator::In];
    }
}
