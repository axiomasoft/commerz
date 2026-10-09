<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use Commerz\Attributes\Api;
use Commerz\Context\CommerceContext;

/** What an activator is asked: the codes presented with the quote and its own candidate promotions. */
#[Api(since: '0.1')]
final readonly class ActivationRequest
{
    /**
     * @param  list<string>  $codes  as presented, cut to the configured maximum; the activator normalizes them
     * @param  list<PromotionSummary>  $promotions  the candidates of this activator in this stage
     */
    public function __construct(public CommerceContext $context, public array $codes, public array $promotions) {}
}
