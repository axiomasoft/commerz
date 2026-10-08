<?php

declare(strict_types=1);

namespace Commerz\Arch;

/** Outcome of one gate: how many subjects it inspected and which of them violate the rule. */
final readonly class GateResult
{
    /** @param  list<string>  $violations  `<path>: <message>` */
    public function __construct(public int $checked, public array $violations) {}

    public function passes(): bool
    {
        return $this->checked > 0 && $this->violations === [];
    }

    public function describe(): string
    {
        if ($this->checked === 0) {
            return 'gate inspected nothing (0 subjects)';
        }

        return implode("\n", $this->violations);
    }
}
