<?php

declare(strict_types=1);

namespace Commerz\EndToEnd\Fixtures;

use Commerz\Identity\CustomerRef;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Customers\Data\CustomerProfile;

/** The CRM's accounts as the commerce core sees them (`commerz.customers`): one B2B client, everyone else a guest. */
final class CrmCustomers implements CustomerDirectory
{
    public const string ACME = 'crm.account:acme';

    public function find(CustomerRef $customer): ?CustomerProfile
    {
        return (string) $customer === self::ACME
            ? new CustomerProfile($customer, ['b2b'], 'billing@acme.test', 'ACME LLC')
            : null;
    }
}
