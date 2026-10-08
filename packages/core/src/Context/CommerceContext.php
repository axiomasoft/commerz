<?php

declare(strict_types=1);

namespace Commerz\Context;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use DateTimeZone;

/** Everything a calculation or sale needs to know about where, for whom and when. Passed explicitly. */
#[Api(since: '0.1')]
final readonly class CommerceContext
{
    public DateTimeImmutable $at;

    /** @var list<string> */
    public array $customerGroups;

    /**
     * @param  list<string>  $customerGroups
     */
    public function __construct(
        public ChannelId $channel,
        public Currency $currency,
        public string $locale,
        public ?CustomerRef $customer,
        array $customerGroups,
        DateTimeImmutable $at,
    ) {
        if (preg_match('/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/', $locale) !== 1) {
            throw new InvalidCommand('locale', "'{$locale}' is not a locale tag", 'context.invalid_locale');
        }

        foreach ($customerGroups as $group) {
            if (preg_match('/\A[a-z0-9_.\-]{1,64}\z/', $group) !== 1) {
                throw new InvalidCommand('customerGroups', "'{$group}' must match [a-z0-9_.-]{1,64}", 'context.invalid_group');
            }
        }

        $this->customerGroups = array_values(array_unique($customerGroups));
        $this->at = $at->setTimezone(new DateTimeZone('UTC'));
    }

    public static function for(
        ChannelId $channel,
        Currency $currency,
        string $locale = 'en',
        ?DateTimeImmutable $at = null,
    ): self {
        return new self($channel, $currency, $locale, null, [], $at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** @param  list<string>  $groups */
    public function withCustomer(CustomerRef $customer, array $groups = []): self
    {
        return new self($this->channel, $this->currency, $this->locale, $customer, $groups, $this->at);
    }

    public function withAt(DateTimeImmutable $at): self
    {
        return new self($this->channel, $this->currency, $this->locale, $this->customer, $this->customerGroups, $at);
    }
}
