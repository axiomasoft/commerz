<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\Identity\ActorRef;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class StageQuote
{
    /** @param list<StagedLineOverride> $lineOverrides */
    public function __construct(public QuoteRequestId $quoteRequestId, public ActorRef $sellerActor, public ?DateTimeImmutable $validUntil = null, public ?PaymentTerms $paymentTerms = null, public array $lineOverrides = [], public ?string $sellerNote = null) {}
}
