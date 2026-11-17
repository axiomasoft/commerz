<?php

declare(strict_types=1);

/**
 * Package map for the architecture gates (docs/architecture/packages.md): directory → kind and the table nouns the
 * package may create tables for. `extra` lists additional Composer requirements a package may declare besides
 * `php`, `ext-*`, `illuminate/*`, `brick/math` and its allowed `axiomasoft/*` packages.
 */
return [
    'core' => ['kind' => 'core', 'nouns' => ['store', 'channel', 'schema', 'operation', 'outbox'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'catalog' => ['kind' => 'feature', 'nouns' => ['product', 'variant', 'option', 'attribute', 'category', 'listing']],
    'inventory' => ['kind' => 'feature', 'nouns' => ['stock']],
    'pricing' => ['kind' => 'feature', 'nouns' => ['price', 'tax']],
    'promotions' => ['kind' => 'feature', 'nouns' => ['promotion', 'coupon']],
    'shipping' => ['kind' => 'feature', 'nouns' => ['shipping']],
    'sales' => ['kind' => 'feature', 'nouns' => ['cart', 'checkout', 'order', 'payment', 'fulfillment', 'return', 'refund']],
    'testkit' => ['kind' => 'testkit', 'nouns' => [], 'extra' => ['orchestra/*']],
    'full' => ['kind' => 'meta', 'nouns' => []],
];
