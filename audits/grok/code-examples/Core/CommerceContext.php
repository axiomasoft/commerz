<?php

declare(strict_types=1);

namespace Commerz\Context\Data;

use Commerz\Attributes\Api;
use Commerz\Money\Data\Currency;
use DateTimeImmutable;

/**
 * Неизменяемый контекст операции. Передаётся явно в каждую команду и расчёт; никогда не хранится в singleton,
 * поэтому корректен в Octane/очередях и при DB-per-tenant.
 */
#[Api(since: '1.0')]
final readonly class CommerceContext
{
    /** @param list<string> $customerGroups коды групп от CustomerResolver хоста */
    public function __construct(
        public string $storeId,
        public string $channelId,
        public Currency $currency,
        public string $locale,
        public DateTimeImmutable $at,
        public ?string $customerRef = null,
        public array $customerGroups = [],
    ) {}
}
