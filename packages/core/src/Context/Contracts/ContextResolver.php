<?php

declare(strict_types=1);

namespace Commerz\Context\Contracts;

use Commerz\Attributes\Api;
use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Context\CommerceContext;
use Commerz\Stores\Data\ChannelId;

/**
 * Host hook that assembles a context from whatever the host has at hand (request, session, CRM user).
 * The package never calls it implicitly: contexts are always passed explicitly.
 */
#[Api(since: '0.1')]
#[Spi(SpiKind::Implement)]
interface ContextResolver
{
    /** @param  ChannelId|null  $channel  the channel the caller already knows; null lets the host choose */
    public function resolve(?ChannelId $channel = null): CommerceContext;
}
