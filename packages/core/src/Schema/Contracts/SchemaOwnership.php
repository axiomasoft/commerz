<?php

declare(strict_types=1);

namespace Commerz\Schema\Contracts;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidConfiguration;

/**
 * Which package owns which table nouns. A logical table belongs to the owner of a noun when it equals the noun, its
 * plural, or starts with "{noun}_" (`stock` owns `stock_levels`, `store` owns `stores`). Third-party plugins use
 * nouns `x_<vendor>`. `TableNames` refuses logical tables without an owner.
 *
 * A package declares its nouns from its service provider, independent of provider order:
 * `$this->callAfterResolving(SchemaOwnership::class, fn (SchemaOwnership $o) => $o->declare('commerz.catalog', [...]))`.
 */
#[Api(since: '0.1')]
interface SchemaOwnership
{
    /**
     * @param  string  $owner  package or plugin id, e.g. `commerz.core`
     * @param  list<string>  $nouns  lower-case snake nouns
     *
     * @throws InvalidConfiguration when another owner already declared one of the nouns
     */
    public function declare(string $owner, array $nouns): void;

    /** Owner id of a logical table name (`stock_levels`), or null when no declared noun covers it. */
    public function ownerOf(string $logical): ?string;

    /** @return array<string, list<string>> owner id => nouns, in declaration order */
    public function owners(): array;
}
