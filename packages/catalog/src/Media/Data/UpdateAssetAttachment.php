<?php

declare(strict_types=1);

namespace Commerz\Catalog\Media\Data;

use Commerz\Catalog\Media\Enums\AssetRole;
use Commerz\Catalog\Options\Data\OptionValueId;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** Updates an existing asset attachment; null fields stay unchanged unless their clear flag is set. */
#[Api(since: '0.1')]
final readonly class UpdateAssetAttachment
{
    public function __construct(
        public AssetAttachmentId $attachment,
        public ?AssetRole $role = null,
        public ?LocalizedText $alt = null,
        public bool $clearAlt = false,
        public ?int $position = null,
        public ?OptionValueId $optionValue = null,
        public bool $clearOptionValue = false,
    ) {
        if ($position !== null && $position < 0) {
            throw new InvalidCommand('position', 'must not be negative');
        }
    }
}
