<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal;

use Packman\Attributes\Internal;

/** A validated attribute change of one owner that is not written yet (`AttributeWriter::prepare`). */
#[Internal]
final readonly class PreparedAttributes
{
    /**
     * @param  array<array-key, mixed>  $document  the owner's next stored document
     * @param  list<array<string, string|bool|null>>  $facts  its projection rows
     */
    public function __construct(
        public AttributeOwner $owner,
        public array $document,
        public array $facts,
    ) {}
}
