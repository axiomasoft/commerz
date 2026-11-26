<?php

declare(strict_types=1);

namespace Commerz\Links\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Links\Enums\CascadeUnlinkPolicy;
use Commerz\Links\Enums\LinkCardinality;
use Packman\Attributes\Api;

/**
 * Declares a named cross-module link type between two logical entity types,
 * including its cardinality and cascade unlink policy.
 */
#[Api(since: '0.1')]
final readonly class LinkDefinition
{
    public function __construct(
        public string $code,
        public string $leftType,
        public string $rightType,
        public LinkCardinality $cardinality = LinkCardinality::ManyToMany,
        public CascadeUnlinkPolicy $cascadeUnlink = CascadeUnlinkPolicy::Unlink,
        public ?string $description = null,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_.\-]{0,127}\z/', $this->code) !== 1) {
            throw new InvalidCommand('code', "'{$this->code}' must match [a-z][a-z0-9_.-]{0,127}", 'links.invalid_link_code');
        }

        if (preg_match('/\A[a-z0-9_.\-]{1,64}\z/', $this->leftType) !== 1) {
            throw new InvalidCommand('leftType', "'{$this->leftType}' must match [a-z0-9_.-]{1,64}", 'links.invalid_entity_type');
        }

        if (preg_match('/\A[a-z0-9_.\-]{1,64}\z/', $this->rightType) !== 1) {
            throw new InvalidCommand('rightType', "'{$this->rightType}' must match [a-z0-9_.-]{1,64}", 'links.invalid_entity_type');
        }
    }
}
