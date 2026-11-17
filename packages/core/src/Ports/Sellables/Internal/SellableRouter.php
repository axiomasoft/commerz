<?php

declare(strict_types=1);

namespace Commerz\Ports\Sellables\Internal;

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use LogicException;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;

/** Routes each ref type to the provider contributed under that key; one provider call per type. */
#[Internal]
final readonly class SellableRouter implements Sellables
{
    public function __construct(private Contributions $contributions) {}

    public function resolve(SellableRef $ref, CommerceContext $context): SellableSnapshot
    {
        return $this->resolveMany([$ref], $context)[(string) $ref];
    }

    public function resolveMany(array $refs, CommerceContext $context): array
    {
        $byType = [];

        foreach ($refs as $ref) {
            $byType[$ref->type][(string) $ref] = $ref;
        }

        $found = [];

        foreach ($byType as $type => $typeRefs) {
            $provider = $this->contributions->get(SellableProvider::KIND, $type, SellableProvider::class);

            foreach ($provider->resolve(array_values($typeRefs), $context) as $key => $snapshot) {
                if (! isset($typeRefs[$key])) {
                    continue;
                }

                if ((string) $snapshot->ref !== $key) {
                    throw new LogicException("Sellable provider '{$type}' returned a snapshot of '{$snapshot->ref}' under '{$key}'.");
                }

                $found[$key] = $snapshot;
            }
        }

        $result = [];

        foreach ($refs as $ref) {
            $key = (string) $ref;
            $result[$key] = $found[$key] ?? throw new SellableUnavailable($key);
        }

        return $result;
    }
}
