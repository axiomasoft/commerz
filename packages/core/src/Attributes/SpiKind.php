<?php

declare(strict_types=1);

namespace Commerz\Attributes;

/** Who is expected to touch an SPI type. */
#[Api(since: '0.1')]
enum SpiKind: string
{
    /** Hosts and plugins write their own implementation. */
    case Implement = 'implement';

    /** Hosts and plugins only call or type-hint it; the package supplies the implementation. */
    case Consume = 'consume';
}
