<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Data;

use Commerz\Catalog\Collections\Enums\CollectionKind;
use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** Creates either a manual collection (`$smart = null`) or a smart collection (`$smart` rules given). */
#[Api(since: '0.1')]
final readonly class CreateCollection
{
    public CollectionKind $kind;

    public function __construct(
        public string $code,
        public LocalizedText $name,
        public ?LocalizedText $description = null,
        public ?SmartCollection $smart = null,
    ) {
        CatalogCodes::code('code', $code);
        $this->kind = $smart instanceof SmartCollection ? CollectionKind::Smart : CollectionKind::Manual;
    }

    public static function manual(string $code, LocalizedText $name, ?LocalizedText $description = null): self
    {
        return new self($code, $name, $description);
    }

    public static function smart(string $code, LocalizedText $name, SmartCollection $smart, ?LocalizedText $description = null): self
    {
        return new self($code, $name, $description, $smart);
    }
}
