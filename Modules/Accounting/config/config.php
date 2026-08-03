<?php

return [
    'name' => 'Accounting',

    /*
     * The Turkish central bank's statistics service (EVDS).
     *
     * Free, but it needs a registered account. Without it Kargah still records
     * the reference and stablecoin rates and simply has no TCMB buying or
     * selling rate, which means an invoice to a domestic Turkish company cannot
     * show its legally required lira equivalent. The fetch command says so
     * rather than substituting a market rate.
     */
    'evds_api_key' => env('EVDS_API_KEY'),

    /*
     * Optional. CoinGecko's public endpoint needs no key and is limited to a
     * few calls a minute; a free demo key raises that. Kargah calls it once a
     * day either way, so this exists only for installs that share an IP with
     * something noisier.
     */
    'coingecko_api_key' => env('COINGECKO_API_KEY'),

    /*
     * Frankfurter is self-hostable. Point this at your own instance to stop
     * depending on a third party for the reference rate.
     */
    'frankfurter_url' => env('FRANKFURTER_URL', 'https://api.frankfurter.dev/v1'),
];
