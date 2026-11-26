<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/**
 * Commercial card Level 3 line item passed to gateways advertising {@see PaymentCapabilities::$lineItemsL3}.
 */
#[Api(since: '0.1')]
final readonly class GatewayLineItem
{
    private const string CODE = 'sales.invalid_gateway_payment';

    public string $sku;

    public string $name;

    public ?string $commodityCode;

    public ?string $unitOfMeasure;

    public function __construct(
        string $sku,
        string $name,
        public int $quantity,
        public Money $unitPrice,
        public Money $total,
        public ?Money $taxAmount = null,
        ?string $commodityCode = null,
        ?string $unitOfMeasure = null,
    ) {
        $sku = trim($sku);
        $name = trim($name);
        $commodityCode = $commodityCode !== null ? trim($commodityCode) : null;
        $unitOfMeasure = $unitOfMeasure !== null ? trim($unitOfMeasure) : null;

        if ($sku === '' || strlen($sku) > 191) {
            throw new InvalidCommand('sku', 'must be 1..191 bytes', self::CODE);
        }

        if ($name === '' || strlen($name) > 191) {
            throw new InvalidCommand('name', 'must be 1..191 bytes', self::CODE);
        }

        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', self::CODE);
        }

        if ($unitPrice->isNegative()) {
            throw new InvalidCommand('unitPrice', 'must not be negative', self::CODE);
        }

        if ($total->isNegative()) {
            throw new InvalidCommand('total', 'must not be negative', self::CODE);
        }

        if (! $total->currency->equals($unitPrice->currency)) {
            throw new InvalidCommand('total', 'must match unitPrice currency', 'sales.currency_mismatch');
        }

        if ($taxAmount !== null) {
            if ($taxAmount->isNegative()) {
                throw new InvalidCommand('taxAmount', 'must not be negative', self::CODE);
            }

            if (! $taxAmount->currency->equals($unitPrice->currency)) {
                throw new InvalidCommand('taxAmount', 'must match unitPrice currency', 'sales.currency_mismatch');
            }
        }

        if ($commodityCode !== null && ($commodityCode === '' || strlen($commodityCode) > 64)) {
            throw new InvalidCommand('commodityCode', 'must be null or 1..64 bytes', self::CODE);
        }

        if ($unitOfMeasure !== null && ($unitOfMeasure === '' || strlen($unitOfMeasure) > 32)) {
            throw new InvalidCommand('unitOfMeasure', 'must be null or 1..32 bytes', self::CODE);
        }

        $this->sku = $sku;
        $this->name = $name;
        $this->commodityCode = $commodityCode;
        $this->unitOfMeasure = $unitOfMeasure;
    }
}
