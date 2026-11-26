<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class WebhookDeliveryId extends EntityId {}
