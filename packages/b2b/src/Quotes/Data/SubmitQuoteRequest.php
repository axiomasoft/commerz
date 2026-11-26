<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Ports\Delivery\Data\Address;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class SubmitQuoteRequest
{
    /** @var list<QuoteRequestLineInput> */
    public array $lines;

    /** @var list<string> */
    public array $codes;

    /**
     * @param  list<QuoteRequestLineInput>  $lines
     * @param  list<string>  $codes
     */
    public function __construct(
        public BusinessUnitId $businessUnitId,
        public CustomerRef $buyer,
        public CommerceContext $context,
        array $lines,
        public ?string $sourceCartId = null,
        public ?PaymentTerms $requestedPaymentTerms = null,
        public ?string $purchaseOrderNumber = null,
        public ?string $buyerNote = null,
        public ?Address $shippingAddress = null,
        public ?Address $billingAddress = null,
        public ?string $deliveryMethod = null,
        array $codes = [],
    ) {
        if ($lines === []) {
            throw new InvalidCommand('lines', 'quote request must contain at least one line', 'b2b.empty_quote_request');
        }

        $ids = array_map(static fn (QuoteRequestLineInput $l): string => $l->lineId, $lines);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidCommand('lines', 'line IDs must be unique', 'b2b.duplicate_quote_line');
        }

        foreach ($lines as $line) {
            if ($line->targetUnitPrice !== null && ! $line->targetUnitPrice->currency->equals($context->currency)) {
                throw new InvalidCommand('targetUnitPrice', 'currency must match context currency', 'b2b.currency_mismatch');
            }
        }

        $normalizedCodes = [];
        foreach ($codes as $code) {
            $trimmed = trim($code);
            if ($trimmed !== '') {
                $normalizedCodes[$trimmed] = $trimmed;
            }
        }

        $this->lines = $lines;
        $this->codes = array_values($normalizedCodes);
    }
}
