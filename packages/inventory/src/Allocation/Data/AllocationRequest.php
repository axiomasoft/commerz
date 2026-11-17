<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class AllocationRequest
{
    /**
     * @param  list<AllocationNeed>  $needs  one per sellable, never empty
     * @param  list<SourceStock>  $sources  in the channel's order: priority ascending, then location code
     */
    public function __construct(
        public ChannelId $channel,
        public array $needs,
        public array $sources,
    ) {
        if ($needs === []) {
            throw new InvalidCommand('needs', 'must not be empty', 'inventory.invalid_allocation');
        }

        $sellables = [];

        foreach ($needs as $need) {
            $key = (string) $need->sellable;

            if (isset($sellables[$key])) {
                throw new InvalidCommand('needs', "'{$key}' appears more than once", 'inventory.invalid_allocation');
            }

            $sellables[$key] = true;
        }

        $locations = [];

        foreach ($sources as $source) {
            $key = (string) $source->location;

            if (isset($locations[$key])) {
                throw new InvalidCommand('sources', "location '{$key}' appears more than once", 'inventory.invalid_allocation');
            }

            $locations[$key] = true;
        }
    }
}
