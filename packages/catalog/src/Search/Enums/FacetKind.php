<?php

declare(strict_types=1);

namespace Commerz\Catalog\Search\Enums;

use Packman\Attributes\Api;

/** Source of a catalog search facet: a filterable attribute, a variant option axis, or auto-detected. */
#[Api(since: '0.1')]
enum FacetKind: string
{
    case Auto = 'auto';
    case Attribute = 'attribute';
    case Option = 'option';
}
