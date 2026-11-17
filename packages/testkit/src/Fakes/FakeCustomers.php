<?php

declare(strict_types=1);

namespace Commerz\Testkit\Fakes;

use Commerz\Identity\CustomerRef;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Customers\Data\CustomerProfile;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final class FakeCustomers implements CustomerDirectory
{
    /** @var array<string, CustomerProfile> */
    private array $profiles = [];

    public function add(CustomerProfile ...$profiles): self
    {
        foreach ($profiles as $profile) {
            $this->profiles[(string) $profile->ref] = $profile;
        }

        return $this;
    }

    public function find(CustomerRef $customer): ?CustomerProfile
    {
        return $this->profiles[(string) $customer] ?? null;
    }
}
