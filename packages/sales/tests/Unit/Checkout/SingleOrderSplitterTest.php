<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Identity\SellerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Quotes\Data\QuotedLine;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Data\QuoteTotals;
use Commerz\Sales\Checkout\Internal\SingleOrderSplitter;
use Commerz\Stores\Data\ChannelId;

it('keeps all quoted line ids in a single group by default', function (): void {
    $usd = Currency::of('USD');
    $context = new CommerceContext(ChannelId::generate(), $usd, 'en-US');

    $line1 = new QuotedLine(
        'line-1',
        new SellableSnapshot(SellableRef::of('cat', '1'), 'SKU-1', 'One', SellableKind::Physical, true, sellerRef: SellerRef::of('seller', 'a')),
        1,
        Money::ofMinor(1000, $usd),
        null,
        false,
        'base',
        Money::ofMinor(1000, $usd),
        [],
        [],
        Money::ofMinor(1000, $usd),
    );
    $line2 = new QuotedLine(
        'line-2',
        new SellableSnapshot(SellableRef::of('cat', '2'), 'SKU-2', 'Two', SellableKind::Physical, true, sellerRef: SellerRef::of('seller', 'b')),
        2,
        Money::ofMinor(500, $usd),
        null,
        false,
        'base',
        Money::ofMinor(1000, $usd),
        [],
        [],
        Money::ofMinor(1000, $usd),
    );

    $quote = new QuoteResult(
        $context,
        [$line1, $line2],
        [],
        null,
        [],
        [],
        new QuoteTotals(
            Money::ofMinor(2000, $usd),
            Money::zero($usd),
            Money::zero($usd),
            Money::zero($usd),
            Money::ofMinor(2000, $usd),
        ),
        str_repeat('a', 64),
    );

    $splitter = new SingleOrderSplitter;

    expect($splitter->split($quote))->toBe([['line-1', 'line-2']]);
});
