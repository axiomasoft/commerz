<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

/** Id of a sales channel (site, POS, marketplace, CRM desk). */
#[Api(since: '0.1')]
final readonly class ChannelId extends EntityId {}
