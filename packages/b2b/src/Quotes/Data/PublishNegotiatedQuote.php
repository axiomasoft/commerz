<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\Identity\ActorRef;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class PublishNegotiatedQuote
{
    public function __construct(
        public StagedQuoteId $stagedQuoteId,
        public ActorRef $sellerActor,
    ) {}
}
