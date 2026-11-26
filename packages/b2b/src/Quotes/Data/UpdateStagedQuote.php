<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\Identity\ActorRef;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class UpdateStagedQuote
{
    /** @param list<StagedLineOverride>|null $lineOverrides */
    public function __construct(public StagedQuoteId $stagedQuoteId, public ActorRef $sellerActor, public ?DateTimeImmutable $validUntil = null, public ?PaymentTerms $paymentTerms = null, public ?array $lineOverrides = null, public ?string $sellerNote = null) {}
}
