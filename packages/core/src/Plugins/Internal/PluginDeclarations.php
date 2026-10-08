<?php

declare(strict_types=1);

namespace Commerz\Plugins\Internal;

use Commerz\Plugins\ContributionKind;

/** What one plugin declared in `register()`; sealed afterwards. */
final class PluginDeclarations
{
    /** @var list<ContributionKind> */
    private array $kinds = [];

    /** @var list<array{kind: string, contribution: object|class-string}> */
    private array $contributions = [];

    private bool $sealed = false;

    public function addKind(ContributionKind $kind): void
    {
        $this->kinds[] = $kind;
    }

    /** @param  object|class-string  $contribution */
    public function addContribution(string $kind, object|string $contribution): void
    {
        $this->contributions[] = ['kind' => $kind, 'contribution' => $contribution];
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    public function sealed(): bool
    {
        return $this->sealed;
    }

    /** @return list<ContributionKind> */
    public function kinds(): array
    {
        return $this->kinds;
    }

    /** @return list<array{kind: string, contribution: object|class-string}> */
    public function contributions(): array
    {
        return $this->contributions;
    }
}
