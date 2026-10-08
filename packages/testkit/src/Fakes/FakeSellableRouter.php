<?php

declare(strict_types=1);

namespace Commerz\Testkit\Fakes;

use Commerz\Attributes\Api;
use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;

/** `Sellables` bound by `Commerce::fake()`: every ref type is answered from one `FakeSellables`. */
#[Api(since: '0.1')]
final readonly class FakeSellableRouter implements Sellables
{
    public function __construct(private FakeSellables $sellables) {}

    public function resolve(SellableRef $ref, CommerceContext $context): SellableSnapshot
    {
        return $this->sellables->find($ref) ?? throw new SellableUnavailable((string) $ref);
    }

    public function resolveMany(array $refs, CommerceContext $context): array
    {
        $result = [];

        foreach ($refs as $ref) {
            $result[(string) $ref] = $this->resolve($ref, $context);
        }

        return $result;
    }
}
