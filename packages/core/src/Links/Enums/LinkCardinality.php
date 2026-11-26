<?php

declare(strict_types=1);

namespace Commerz\Links\Enums;

use Commerz\Links\Contracts\LinkRegistry;
use Packman\Attributes\Api;

/**
 * Cardinality constraint enforced by {@see LinkRegistry} for a link definition.
 */
#[Api(since: '0.1')]
enum LinkCardinality: string
{
    case OneToOne = 'one_to_one';
    case OneToMany = 'one_to_many';
    case ManyToOne = 'many_to_one';
    case ManyToMany = 'many_to_many';
}
