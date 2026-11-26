<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Sales\Payments\Contracts\PaymentCustomization;
use Commerz\Sales\Payments\Enums\PaymentCustomizationAction;
use Packman\Attributes\Api;

/** Declarative operation returned by a {@see PaymentCustomization} rule. */
#[Api(since: '0.1')]
final readonly class PaymentMethodOperation
{
    private const string CODE = 'sales.invalid_payment';

    public string $methodKey;

    public ?string $name;

    public function __construct(
        public PaymentCustomizationAction $action,
        string $methodKey,
        ?string $name = null,
        public ?int $position = null,
    ) {
        $methodKey = trim($methodKey);
        $name = $name !== null ? trim($name) : null;

        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $methodKey) !== 1) {
            throw new InvalidCommand('methodKey', 'must be a contribution key of 1..64 characters [a-z0-9_.-]', self::CODE);
        }

        if ($action === PaymentCustomizationAction::Rename && ($name === null || $name === '' || strlen($name) > 191)) {
            throw new InvalidCommand('name', 'rename operation requires a name of 1..191 bytes', self::CODE);
        }

        if ($action === PaymentCustomizationAction::Move && ($position === null || $position < 0)) {
            throw new InvalidCommand('position', 'move operation requires a non-negative position', self::CODE);
        }

        $this->methodKey = $methodKey;
        $this->name = $name;
    }

    public static function hide(string $methodKey): self
    {
        return new self(PaymentCustomizationAction::Hide, $methodKey);
    }

    public static function rename(string $methodKey, string $name): self
    {
        return new self(PaymentCustomizationAction::Rename, $methodKey, name: $name);
    }

    public static function move(string $methodKey, int $position): self
    {
        return new self(PaymentCustomizationAction::Move, $methodKey, position: $position);
    }
}
