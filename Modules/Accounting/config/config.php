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
     * The owner's profit-and-loss currency — the one every invoice freezes a
     * figure in at issue, so a year can be added up without re-deriving rates
     * that have since moved.
     *
     * Shipped as TRY because the owner is in Turkey and declares in lira. It
     * was USD, buried as a default in `InvoiceIssuer::issue()`'s signature
     * where nobody could see it and nothing ever overrode it, which left the
     * lira reports structurally near-empty.
     *
     * 🔴 **Changing this does not move a single invoice that has already been
     * issued.** `reporting_currency`, `reporting_rate` and `reporting_amount`
     * are frozen on the row at issue and are the record of what was believed
     * then; nothing backfills them and nothing recomputes them. So a book that
     * has run for a while is a *mixed* book — older invoices reporting in one
     * currency, newer ones in another — and that is the normal state, not an
     * error. Every total on the reports page groups by each row's own
     * `reporting_currency` for exactly this reason.
     *
     * 🔴 Note what Kargah can actually convert. `rateFor()` inverts a stored
     * pair but will not chain two, so a reporting currency is only useful for
     * the pairs `accounting:fetch-rates` actually stores: **USD/TRY** (TCMB and
     * Frankfurter) and **USDT/USD and USDT/TRY** (CoinGecko).
     *
     * The USDT/TRY leg was added precisely so that lira could be the reporting
     * currency without stablecoin invoices falling out of every total — see
     * `CoinGecko`'s class docblock for why it is fetched directly rather than
     * derived by multiplying two rates. It is **optional**: if CoinGecko stops
     * quoting the lira price, a USDT invoice goes back to freezing null
     * reporting figures. That never blocks issuing — `reportingFigures()` writes
     * nulls and every page counts the invoice out loud — but such an invoice
     * contributes nothing to a lira total for as long as the rate is missing.
     *
     * Must be one of `Modules\Accounting\Support\Currencies::supported()`.
     * Anything else falls back to TRY rather than refusing to issue, because
     * Kargah must never block an invoice on a setting.
     */
    'reporting_currency' => env('ACCOUNTING_REPORTING_CURRENCY', 'TRY'),

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
         * judgement per invoice, not something software may assume — see
         * `kdv_exemptions` below for how Kargah records that judgement without
         * ever making it.
         */
        'kdv_percent' => env('ACCOUNTING_KDV_PERCENT', '20'),

        /*
         * The KDV exemption codes an invoice may be raised under, by the code
         * printed on the document.
         *
         * 🔴 **Kargah never selects one of these.** An invoice's
         * `kdv_exemption_code` is null unless a person opened the invoice,
         * confirmed each of the conditions in turn and applied it — the
         * exemption is a judgement the freelancer makes with their mali
         * müşavir, per invoice, and inferring it from "the client is abroad"
         * would be software deciding a tax question on somebody's behalf.
         * `null` is the default and means the standard rate applies.
         *
         * `label` is what the invoice document prints beside the code, and
         * `conditions` is the checklist the operator ticks. Both are here
         * rather than in a Blade template so that adding an exemption code, or
         * correcting the wording of one, is a configuration change and not a
         * view change.
         *
         * Sourced from ACCOUNTING-RESEARCH §4.1 (vergimerkezi.com.tr,
         * muhasebenews.com). Nothing here is tax advice and none of it was
         * verified against the Gelir İdaresi Başkanlığı's own publications —
         * the page says so beside the control.
         */
        'kdv_exemptions' => [
            '302' => [
                'label' => 'Hizmet ihracatı — export of services',
                'conditions' => [
                    'My business centre is in Turkey and I produced this service with a Turkey-based organisation.',
                    'The client\'s residence or business centre is abroad.',
                    'The service is benefited from abroad.',
                    'This invoice is issued to the foreign client at a KDV rate of zero, under exemption code 302.',
                ],
            ],
        ],

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

    /*
     * The printed invoice — the one artefact in Kargah a stranger reads.
     *
     * These are the practice's own marks rather than tax data, so nothing here
     * affects a figure. They live in config and not in the template because the
     * template is shared code and a business's own name is not.
     */
    'document' => [
        /*
         * Printed under the signature. The owner's site, and the only branding
         * on the page: an invoice that shouts is an invoice that reads as
         * marketing.
         */
        'footer' => env('ACCOUNTING_DOCUMENT_FOOTER', 'lavzen.com'),

        /*
         * The name under the signature line. Printed whether or not an image
         * exists, because a typed name beside a date is still a signature block
         * and an empty rule with nothing under it is not.
         */
        'signature_name' => env('ACCOUNTING_SIGNATURE_NAME', 'Hesam Ahmadpour'),

        /*
         * 🔴 A path relative to `public/`, or null.
         *
         * Read and inlined as a base64 `data:` URI rather than passed to dompdf
         * as a path. dompdf runs with `isRemoteEnabled => false` and its own
         * chroot, so a path that resolves in PHP does not necessarily resolve
         * inside the renderer — and a missing image there fails by drawing
         * nothing, with no error anywhere. A data URI cannot miss.
         *
         * **A missing file is not an error.** The signature block falls back to
         * the typed name and the date. An invoice that refuses to render because
         * a decoration is absent would be a worse failure than an unsigned one.
         */
        'signature_image' => env('ACCOUNTING_SIGNATURE_IMAGE', 'img/signature.png'),
    ],
];
