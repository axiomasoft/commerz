<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\RoundingMode;
use Commerz\Sellable\SellingPlan;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class QuoteInput
{
    /** @var list<string> */
    public array $codes;

    /**
     * @param  list<QuoteLineInput>  $lines
     * @param  list<string>  $codes  codes the buyer presented; a code is only applied here, its usage is counted when
     *                               the order is placed
     */
    public function __construct(
        public CommerceContext $context,
        public array $lines,
        array $codes = [],
        public ?DeliverySelection $delivery = null,
        public RoundingMode $rounding = RoundingMode::HalfUp,
        public ?SellingPlan $sellingPlan = null,
    ) {
        $ids = array_map(static fn (QuoteLineInput $line): string => $line->lineId, $lines);

        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidCommand('lines', 'line ids must be unique', 'quotes.invalid_line');
        }

        $normalized = [];

        foreach ($codes as $code) {
            $code = trim($code);

            if ($code === '' || strlen($code) > 64) {
                throw new InvalidCommand('codes', 'each code must be 1..64 bytes', 'quotes.invalid_code');
            }

            $normalized[$code] = $code;
        }

        $this->codes = array_values($normalized);
    }

    public function line(string $lineId): ?QuoteLineInput
    {
        return array_find($this->lines, static fn (QuoteLineInput $line): bool => $line->lineId === $lineId);
    }

    /**
     * @param  list<QuoteLineInput>  $lines
     */
    public function withLines(array $lines, ?DeliverySelection $delivery = null): self
    {
        return new self(
            $this->context,
            $lines,
            $this->codes,
            $delivery ?? $this->delivery,
            $this->rounding,
        );
    }
}
