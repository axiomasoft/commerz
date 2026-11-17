<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal\Types;

use Commerz\Identity\Ref;
use Packman\Attributes\Internal;

/** Carries the `Ref` validation rules for values of the `reference` attribute type. */
#[Internal]
final readonly class ValueRef extends Ref {}
