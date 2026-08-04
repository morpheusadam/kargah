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

    /*
     * Turkish tax figures, for the reports page and nothing else.
     *
     * 🔴 These are configuration and not literals in a view for one reason:
     * **Turkish income-tax brackets are revalued every year.** The 2026 figures
     * below came from a revaluation of 18.95% applied to the 2025 ones. A rate
     * hardcoded into a Blade template is a rate nobody remembers to change, and
     * the number it produces is one the owner might hand to a mali müşavir — so
     * the reports page reads every rate from here, prints the year beside it,
     * and says out loud that the operator has to confirm it.
     *
     * Every value is a **decimal string**, never a float: they are multiplied
     * against money and `Modules\Accounting\Support\Money` refuses floats at the
     * door. '15' means fifteen per cent.
     *
     * Sourced, and only as well as the sources allowed — see
     * ACCOUNTING-RESEARCH §4. Nothing here is tax advice and none of it was
     * verified against the Gelir İdaresi Başkanlığı's own publications.
     */
    'tax' => [

        /*
         * The tax year the rates below belong to. Printed on the reports page
         * next to every figure they produce, so a stale config is visible
         * rather than silent.
         */
        'year' => env('ACCOUNTING_TAX_YEAR', '2026'),

        /*
         * KDV (Turkish VAT) on professional services: 20%.
         *
         * The reports page does **not** apply this rate to anything. Each
         * invoice froze its own `tax_percent` and `tax_amount` at issue and the
         * KDV total is added from those; this figure is here only so the page
         * can say which standard rate an invoice would be expected to carry.
         *
         * Export of services to a foreign client can be zero-rated under
         * exemption code 302, but only if four conditions all hold. That is a
         * judgement per invoice, not something software may assume, so Kargah
         * never applies it — it counts the zero-rated invoices and says so.
         */
        'kdv_percent' => env('ACCOUNTING_KDV_PERCENT', '20'),

        /*
         * Geçici vergi — quarterly provisional income tax.
         *
         * Filed four times a year on three-month periods, due the 17th of the
         * second month after each period, at the first bracket of the income
         * tax tariff. Sourced as 15% for 2026, with the first bracket running
         * to TRY 190,000.
         *
         * Kargah only knows the first bracket. Above the threshold a higher
         * band applies and Kargah's estimate understates the liability — the
         * page says so rather than quietly extrapolating.
         */
        'gecici_vergi_percent' => env('ACCOUNTING_GECICI_VERGI_PERCENT', '15'),
        'gecici_vergi_threshold' => env('ACCOUNTING_GECICI_VERGI_THRESHOLD', '190000'),

        /*
         * Stopaj — 20% income tax withheld at source under Income Tax Law
         * Art. 94, remitted by the payer rather than by the freelancer.
         *
         * 🔴 The obligation is sourced only for payments from a **Turkish
         * tax-liable** organisation. Whether it reaches a foreign client paying
         * a Turkish freelancer directly — which is Kargah's main case — could
         * not be verified from any source, and no source said it does not
         * either. So the reports page computes stopaj **only** for invoices
         * whose buyer is a domestic Turkish company (`companies.is_domestic`),
         * and for foreign buyers it prints the open question instead of a
         * number. A confidently wrong withholding figure is worse than none.
         */
        'stopaj_percent' => env('ACCOUNTING_STOPAJ_PERCENT', '20'),
    ],
];
