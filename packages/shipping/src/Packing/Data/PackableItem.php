<?php

declare(strict_types=1);

namespace Commerz\Shipping\Packing\Data;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/** A shippable line item with integer unit weight (grams), total line value, and optional 3D unit dimensions (mm). */
#[Api(since: '0.1')]
final readonly class PackableItem
{
    public function __construct(
        public string $itemId,
        public int $quantity,
        public int $unitWeightGrams,
        public Money $totalValue,
        public ?int $lengthMm = null,
        public ?int $widthMm = null,
        public ?int $heightMm = null,
    ) {
        if (trim($itemId) === '' || strlen($itemId) > 191) {
            throw new InvalidCommand('itemId', 'must be 1..191 bytes', 'shipping.invalid_packing');
        }

        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'shipping.invalid_packing');
        }

        if ($unitWeightGrams < 0) {
            throw new InvalidCommand('unitWeightGrams', 'must not be negative', 'shipping.invalid_packing');
        }

        if ($totalValue->isNegative()) {
            throw new InvalidCommand('totalValue', 'must not be negative', 'shipping.invalid_packing');
        }

        $dims = ['lengthMm' => $lengthMm, 'widthMm' => $widthMm, 'heightMm' => $heightMm];
        $present = 0;

        foreach ($dims as $field => $size) {
            if ($size !== null) {
                $present++;
                if ($size < 1) {
                    throw new InvalidCommand($field, 'must be positive', 'shipping.invalid_packing');
                }
            }
        }

        if ($present !== 0 && $present !== 3) {
            throw new InvalidCommand('dimensions', 'lengthMm, widthMm, and heightMm must all be set or all be null', 'shipping.invalid_packing');
        }
    }

    public static function ofUnitPrice(
        string $itemId,
        int $quantity,
        int $unitWeightGrams,
        Money $unitPrice,
        ?int $lengthMm = null,
        ?int $widthMm = null,
        ?int $heightMm = null,
    ): self {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'shipping.invalid_packing');
        }

        return new self(
            itemId: $itemId,
            quantity: $quantity,
            unitWeightGrams: $unitWeightGrams,
            totalValue: $unitPrice->multiply($quantity),
            lengthMm: $lengthMm,
            widthMm: $widthMm,
            heightMm: $heightMm,
        );
    }

    public function hasDimensions(): bool
    {
        return $this->lengthMm !== null && $this->widthMm !== null && $this->heightMm !== null;
    }

    public function unitVolumeMm3(): BigInteger
    {
        if ($this->lengthMm === null || $this->widthMm === null || $this->heightMm === null) {
            return BigInteger::zero();
        }

        return BigInteger::of($this->lengthMm)
            ->multipliedBy($this->widthMm)
            ->multipliedBy($this->heightMm);
    }

    /**
     * Exact integer volumetric weight of one unit in grams: `ceil((lengthMm * widthMm * heightMm) / volumetricDivisor)`.
     * Since `1 cm³ = 1000 mm³` and `1 kg = 1000 g`, a standard `5000 cm³/kg` divisor equals `5000 mm³/g`.
     */
    public function unitVolumetricWeightGrams(int $volumetricDivisor = 5_000): int
    {
        if ($volumetricDivisor < 1) {
            throw new InvalidCommand('volumetricDivisor', 'must be positive', 'shipping.invalid_packing');
        }

        $volume = $this->unitVolumeMm3();

        if ($volume->isZero()) {
            return 0;
        }

        return BigDecimal::of($volume)
            ->dividedBy($volumetricDivisor, 0, RoundingMode::Ceiling)
            ->toInt();
    }
}
