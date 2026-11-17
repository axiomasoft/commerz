<?php

declare(strict_types=1);

namespace Commerz\Ports\Prices\Contracts;

use Commerz\Context\CommerceContext;
use Commerz\Ports\Prices\Data\ResolvedPrice;
use Commerz\Ports\Prices\Data\ResolvePrice;
use Commerz\Ports\Prices\Exceptions\PriceNotFound;
use Packman\Attributes\Api;

/** Unit prices in the context's channel, currency, customer groups and moment. Reads only. */
#[Api(since: '0.1')]
interface PriceResolver
{
    /**
     * @param  list<ResolvePrice>  $requests
     * @return list<ResolvedPrice> one per request, in the same order, in `$context->currency`
     *
     * @throws PriceNotFound
     */
    public function resolveMany(array $requests, CommerceContext $context): array;
}
