<?php

declare(strict_types=1);

namespace Commerz\Context\Contracts;

use Commerz\Context\CommerceContext;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Host hook that assembles a context from whatever the host has at hand (request, session, CRM user).
 * The package never calls it implicitly: contexts are always passed explicitly.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface ContextResolver
{
    /** @param  ChannelId|null  $channel  the channel the caller already knows; null lets the host choose */
    public function resolve(?ChannelId $channel = null): CommerceContext;
}
