<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal;

use Commerz\Promotions\Rules\Data\RuleParams;
use Packman\Attributes\Internal;

/** A rule as the database keeps it: the key, the version of the plugin's schema it was written at, and the parameters. */
#[Internal]
final readonly class StoredRule
{
    public function __construct(public string $key, public int $version, public RuleParams $params) {}
}
