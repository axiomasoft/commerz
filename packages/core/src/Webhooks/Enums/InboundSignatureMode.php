<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum InboundSignatureMode: string
{
    /** `t=<unix_timestamp>,v1=<hmac_sha256_hex>` over `<unix_timestamp>.<raw_body>` with clock skew tolerance. */
    case Timestamped = 'timestamped';

    /** `<hmac_sha256_hex>` or `sha256=<hmac_sha256_hex>` directly over `<raw_body>`. */
    case PayloadHmac = 'payload_hmac';
}
