<?php

declare(strict_types=1);

namespace Commerz\Catalog\Routes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ResolveRoute
{
    public function __construct(
        public ChannelId $channel,
        public string $locale,
        public string $slug,
    ) {
        if (trim($locale) === '' || strlen($locale) > 16) {
            throw new InvalidCommand('locale', 'must be 1 to 16 bytes');
        }

        if (trim($slug) === '' || strlen($slug) > 255) {
            throw new InvalidCommand('slug', 'must be 1 to 255 bytes');
        }
    }
}
