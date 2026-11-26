<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Operational constraints that govern which fulfillment locations may handle an order or shipment
 * (e.g. cold chain, oversized freight, no-split shipping, or custom warehouse capability tags).
 */
#[Api(since: '0.1')]
final readonly class FulfillmentConstraints
{
    /** @var list<string> */
    public array $requiredLocationTags;

    /**
     * @param  list<string>  $requiredLocationTags  capability tags every chosen location must declare, e.g. `hazmat`, `bonded`
     */
    public function __construct(
        public bool $coldChain = false,
        public bool $oversized = false,
        public bool $mustShipTogether = false,
        array $requiredLocationTags = [],
    ) {
        foreach ($requiredLocationTags as $tag) {
            if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $tag) !== 1) {
                throw new InvalidCommand('requiredLocationTags', "tag '{$tag}' must match [a-z0-9][a-z0-9_.-]{0,63}", 'delivery.invalid_constraints');
            }
        }

        $this->requiredLocationTags = array_values(array_unique($requiredLocationTags));
    }

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return ! $this->coldChain
            && ! $this->oversized
            && ! $this->mustShipTogether
            && $this->requiredLocationTags === [];
    }

    /** Combines two constraint sets so the result satisfies both. */
    public function merge(self $other): self
    {
        return new self(
            coldChain: $this->coldChain || $other->coldChain,
            oversized: $this->oversized || $other->oversized,
            mustShipTogether: $this->mustShipTogether || $other->mustShipTogether,
            requiredLocationTags: array_values(array_unique([...$this->requiredLocationTags, ...$other->requiredLocationTags])),
        );
    }

    /**
     * @return array{cold_chain: bool, oversized: bool, must_ship_together: bool, required_location_tags: list<string>}
     */
    public function toArray(): array
    {
        return [
            'cold_chain' => $this->coldChain,
            'oversized' => $this->oversized,
            'must_ship_together' => $this->mustShipTogether,
            'required_location_tags' => $this->requiredLocationTags,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawTags = $data['required_location_tags'] ?? [];
        $tags = [];

        if (is_array($rawTags)) {
            foreach ($rawTags as $tag) {
                if (! is_string($tag)) {
                    throw new InvalidCommand('requiredLocationTags', 'every required location tag must be a string', 'delivery.invalid_constraints');
                }

                $tags[] = $tag;
            }
        }

        return new self(
            coldChain: (bool) ($data['cold_chain'] ?? false),
            oversized: (bool) ($data['oversized'] ?? false),
            mustShipTogether: (bool) ($data['must_ship_together'] ?? false),
            requiredLocationTags: $tags,
        );
    }
}
