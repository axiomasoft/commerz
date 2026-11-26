<?php

declare(strict_types=1);

/**
 * Package map for the architecture gates (docs/architecture/packages.md): directory → kind and the table nouns the
 * package may create tables for. `extra` lists additional Composer requirements a package may declare besides
 * `php`, `ext-*`, `illuminate/*`, `brick/math` and its allowed `axiomasoft/*` packages.
 */
return [
    'core' => ['kind' => 'core', 'nouns' => ['store', 'channel', 'schema', 'operation', 'outbox', 'custom_field'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'catalog' => ['kind' => 'feature', 'nouns' => ['product', 'variant', 'option', 'attribute', 'category', 'listing'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'inventory' => ['kind' => 'feature', 'nouns' => ['stock'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'pricing' => ['kind' => 'feature', 'nouns' => ['price', 'tax'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'promotions' => ['kind' => 'feature', 'nouns' => ['promotion', 'coupon'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'shipping' => ['kind' => 'feature', 'nouns' => ['shipping'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'sales' => ['kind' => 'feature', 'nouns' => ['cart', 'checkout', 'order', 'payment', 'fulfillment', 'return', 'refund'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'testkit' => ['kind' => 'testkit', 'nouns' => [], 'extra' => ['orchestra/*', 'axiomasoft/packman', 'axiomasoft/packman-*']],
    'full' => ['kind' => 'meta', 'nouns' => []],
];
