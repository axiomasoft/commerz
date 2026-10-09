<?php

declare(strict_types=1);

namespace Commerz\Shipping\Options\Data;

use Commerz\Attributes\Api;

/** The verdict of every method that is not archived for one delivery request, by method code. */
#[Api(since: '0.1')]
final readonly class OptionsExplanation
{
    /** @param  list<MethodVerdict>  $methods */
    public function __construct(public array $methods) {}

    public function method(string $methodCode): ?MethodVerdict
    {
        return array_find($this->methods, static fn (MethodVerdict $verdict): bool => $verdict->methodCode === $methodCode);
    }
}
