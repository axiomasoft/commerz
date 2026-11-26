<?php

declare(strict_types=1);

/**
 * Package map for the architecture gates (docs/architecture/packages.md): directory → kind and the table nouns the
 * package may create tables for. `extra` lists additional Composer requirements a package may declare besides
 * `php`, `ext-*`, `illuminate/*`, `brick/math` and its allowed `axiomasoft/*` packages.
 */
return [
    'core' => ['kind' => 'core', 'nouns' => ['store', 'channel', 'schema', 'operation', 'outbox', 'entity_link', 'custom_field', 'webhook'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'catalog' => ['kind' => 'feature', 'nouns' => ['product', 'variant', 'option', 'attribute', 'category', 'listing', 'brand', 'asset_attachment', 'url_route', 'collection', 'storefront_product'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'inventory' => ['kind' => 'feature', 'nouns' => ['stock'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'pricing' => ['kind' => 'feature', 'nouns' => ['price', 'staged_price', 'tax', 'market'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'promotions' => ['kind' => 'feature', 'nouns' => ['promotion', 'coupon', 'campaign'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'shipping' => ['kind' => 'feature', 'nouns' => ['shipping'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'sales' => ['kind' => 'feature', 'nouns' => ['cart', 'checkout', 'order', 'payment', 'fulfillment', 'return', 'refund', 'exchange', 'claim'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'stored-value' => ['kind' => 'feature', 'nouns' => ['stored_value'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'subscriptions' => ['kind' => 'feature', 'nouns' => ['subscription'], 'extra' => ['axiomasoft/packman', 'axiomasoft/packman-*']],
    'testkit' => ['kind' => 'testkit', 'nouns' => [], 'extra' => ['orchestra/*', 'axiomasoft/packman', 'axiomasoft/packman-*']],
    'full' => ['kind' => 'meta', 'nouns' => []],
];
