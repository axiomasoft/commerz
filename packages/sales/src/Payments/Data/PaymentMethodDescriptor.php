<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Sales\Gateways\Data\PaymentCapabilities;
use Commerz\Sales\Gateways\Internal\GatewayMeta;
use Commerz\Sales\Payments\Contracts\PaymentCustomization;
use Packman\Attributes\Api;

/** A candidate payment method presented at checkout before and after {@see PaymentCustomization}. */
#[Api(since: '0.1')]
final readonly class PaymentMethodDescriptor
{
    private const string CODE = 'sales.invalid_payment';

    public string $key;

    public string $label;

    /** @param  array<string, scalar>  $meta */
    public function __construct(
        string $key,
        string $label,
        public int $position = 0,
        public ?PaymentCapabilities $capabilities = null,
        public array $meta = [],
    ) {
        $key = trim($key);
        $label = trim($label);

        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $key) !== 1) {
            throw new InvalidCommand('key', 'must be a contribution key of 1..64 characters [a-z0-9_.-]', self::CODE);
        }

        if ($label === '' || strlen($label) > 191) {
            throw new InvalidCommand('label', 'must be 1..191 bytes', self::CODE);
        }

        if ($position < 0) {
            throw new InvalidCommand('position', 'must not be negative', self::CODE);
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);

        $this->key = $key;
        $this->label = $label;
    }

    public function withLabel(string $label): self
    {
        return new self($this->key, $label, $this->position, $this->capabilities, $this->meta);
    }

    public function withPosition(int $position): self
    {
        return new self($this->key, $this->label, $position, $this->capabilities, $this->meta);
    }
}
