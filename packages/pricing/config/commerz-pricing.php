<?php

declare(strict_types=1);

return [
    /*
    | With no applicable price in the channel's price lists, fall back to the sellable's own base price
    | (only when its currency is the context currency); otherwise resolving fails with `prices.price_not_found`.
    */
    'fallback_to_sellable' => true,

    'tax' => [
        /* Key of the contributed `tax_calculator` the tax stage calls: `rates` (built in) or a plugin key such as `acme.avalara`. */
        'calculator' => 'rates',

        /* Tax class of a sellable that names none. */
        'default_class' => 'standard',

        /* Tax class of the delivery charge; null leaves delivery untaxed. */
        /** @var ?string */
        'delivery_class' => 'standard',

        /* Code of the tax zone used when a quote has no delivery address (POS, CRM, digital goods); null: no zone, no tax. */
        'default_zone' => null,

        /* How a tax amount rounds to the minor unit: half_up, half_even, half_down, up, down, ceiling or floor. */
        'rounding' => 'half_up',
    ],
];
