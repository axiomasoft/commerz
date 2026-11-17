<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Internal;

use Commerz\Shipping\Rates\Data\RateParams;
use Packman\Attributes\Internal;

/** A rate as the database keeps it: the key, the version of the plugin's schema it was written at, and the parameters. */
#[Internal]
final readonly class StoredRate
{
    public function __construct(public string $key, public int $version, public RateParams $params) {}
}
