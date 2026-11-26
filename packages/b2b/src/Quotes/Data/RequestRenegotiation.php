<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\CustomerRef;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class RequestRenegotiation
{
    public string $comment;

    public function __construct(
        public NegotiatedQuoteId $negotiatedQuoteId,
        public CustomerRef $buyer,
        string $comment,
        public ?ActorRef $sellerActor = null,
    ) {
        $trimmed = trim($comment);

        if ($trimmed === '' || strlen($trimmed) > 2000) {
            throw new InvalidCommand('comment', 'renegotiation comment must be 1..2000 bytes', 'b2b.invalid_renegotiation_comment');
        }

        $this->comment = $trimmed;
    }
}
