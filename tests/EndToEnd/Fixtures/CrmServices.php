<?php

declare(strict_types=1);

namespace Commerz\EndToEnd\Fixtures;

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;

/**
 * The CRM's service catalogue, held in memory as a host would hold it in its own tables: `crm.service:<code>`, kind
 * Service, nothing to ship, a tariff of its own as the base price and the `standard` tax class.
 */
final class CrmServices implements SellableProvider
{
    public const string KEY = 'crm.service';

    /** code => [name, tariff in RUB] */
    private const array SERVICES = [
        'consulting' => ['Consulting hour', '1500.00'],
        'audit' => ['Security audit', '4000.00'],
    ];

    public static function ref(string $code): SellableRef
    {
        return SellableRef::of(self::KEY, $code);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function resolve(array $refs, CommerceContext $context): array
    {
        $snapshots = [];

        foreach ($refs as $ref) {
            if (! isset(self::SERVICES[$ref->id])) {
                continue;
            }

            [$name, $tariff] = self::SERVICES[$ref->id];
            $snapshots[(string) $ref] = new SellableSnapshot(
                $ref,
                'SRV-'.strtoupper($ref->id),
                $name,
                SellableKind::Service,
                requiresShipping: false,
                basePrice: Money::of($tariff, 'RUB'),
                taxClass: 'standard',
            );
        }

        return $snapshots;
    }
}
