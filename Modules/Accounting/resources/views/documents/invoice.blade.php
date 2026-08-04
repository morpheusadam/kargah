{{--
    The printed invoice.

    Deliberately plain: dompdf supports a subset of CSS and none of the theme's
    bundle, so this is self-contained and uses no class from /assets. It is also
    the one view in Kargah a stranger reads, so every converted figure states the
    rate and date that produced it.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }}</title>
    <style>
        @page { margin: 22mm 18mm; }
        /* White, stated rather than inherited. dompdf defaults to white and so
           does every viewer, so this changes nothing today — but the signature
           is a transparent PNG of black ink, and a transparent image is only
           legible against a background somebody has actually decided on. The
           day a tinted paper or a letterhead is added, this line is what stops
           the signature disappearing into it. */
        html, body { background: #ffffff; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.45; }
        h1 { font-size: 20pt; margin: 0 0 2mm; }
        .muted { color: #6b7280; }
        .small { font-size: 8.5pt; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; padding-bottom: 8mm; }
        .lines th { text-align: left; border-bottom: 1px solid #d1d5db; padding: 2mm 1mm; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        /* Top, so that a line carrying a scope keeps its quantity and its
           amount level with the description's first line instead of drifting
           to the middle of the block. A row with no scope is one line tall and
           renders identically either way. */
        .lines td { padding: 2.5mm 1mm; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
        .num { text-align: right; }
        .label { color: #6b7280; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .04em; }
        .meta .meta-value { margin-bottom: 2.4mm; }

        /* The work under a priced line. A nested table rather than a hanging
           indent: dompdf's most reliable box is a table cell, and this is the
           one construction that is certain to keep a wrapped task aligned
           under the first word rather than under the bullet. */
        .tasks { margin: 1.8mm 0 0.4mm; }
        .lines .tasks td { border-bottom: none; padding: 0.5mm 0; vertical-align: top; color: #374151; font-size: 9pt; }
        .lines .tasks .dot { width: 4mm; color: #9ca3af; }

        .totals { margin-top: 4mm; width: 62mm; float: right; }
        .totals td { padding: 1.5mm 1mm; }
        .totals .grand td { border-top: 1.5px solid #1a1a1a; font-weight: bold; font-size: 12pt; padding-top: 2.5mm; }

        /* 🔴 The two gaps below used to be 12mm and 14mm, and together they were
           what tipped an ordinary one-line invoice onto a second page: measured
           on 4 August 2026, `INV-0042` — one line, a three-line address and a
           tax number — ran to two pages while `INV-0041` with a shorter address
           ran to one. 26mm of fixed whitespace on a 253mm content area is the
           difference between those two, not the content. Reduced to 8mm and
           6mm, which still reads as a separated block and buys back most of a
           line each.

           `page-break-inside: avoid` is the other half: when an invoice is
           genuinely long enough to need a second page, the break has to fall
           *between* these blocks rather than through the middle of a signature
           or a provenance table. dompdf honours it on a block-level box. */
        .sign { clear: both; margin-top: 6mm; page-break-inside: avoid; }
        .sign td { vertical-align: bottom; }
        .sign-block { width: 62mm; }
        .sign-img { height: 15mm; margin-bottom: 1mm; }
        .sign-name { border-top: 1px solid #1a1a1a; padding-top: 1.6mm; }

        /* Fixed, so it sits at the foot of every page a long invoice runs to.
           `bottom: 0` is the one value that reads sensibly under either of
           dompdf's interpretations of the property — the foot of the content
           area, or the foot of the sheet. Nothing else on the page depends on
           where it lands. */
        .footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; color: #9ca3af; font-size: 8pt; letter-spacing: .06em; }

        .provenance { clear: both; margin-top: 8mm; border: 1px solid #e5e7eb; padding: 4mm; font-size: 8.5pt; page-break-inside: avoid; }
        .provenance h3 { margin: 0 0 2mm; font-size: 9pt; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        .provenance dt { float: left; width: 42mm; color: #6b7280; }
        .provenance dd { margin: 0 0 1.2mm 42mm; }
        .hash { font-family: DejaVu Sans Mono, monospace; font-size: 7.5pt; word-break: break-all; }
    </style>
</head>
<body>

@php
    /**
     * The KDV exemption this invoice was raised under, if a person applied one.
     *
     * Read straight off the row rather than passed in, and null unless somebody
     * confirmed every condition on the invoice builder — Kargah never infers a
     * zero-rating from the client being abroad. The label comes from
     * `config/accounting.php` so the wording a tax office reads and the wording
     * the operator confirmed are the same string.
     */
    $exemptionCode = $invoice->kdv_exemption_code ?: null;
    $exemptionLabel = $exemptionCode === null
        ? null
        : config('accounting.tax.kdv_exemptions.'.$exemptionCode.'.label');
@endphp

<table class="head">
    <tr>
        <td style="width: 55%">
            <h1>Invoice</h1>
            <div class="muted">{{ $invoice->number }}</div>
        </td>
        {{-- Label above value rather than beside it. Measured, not preferred:
             "Work period" and a full date range on one line overflow this cell
             and dompdf wraps it mid-range, leaving "30" on one line and
             "September 2026" on the next. --}}
        <td class="num meta">
            <div class="label">Issued</div>
            <div class="meta-value">{{ $invoice->issued_on?->format('j F Y') ?? '—' }}</div>

            <div class="label">Due</div>
            <div class="meta-value">{{ $invoice->due_on?->format('j F Y') ?? '—' }}</div>

            @if ($period)
                {{-- Only when a period was actually agreed. `$period` is null
                     unless at least one of the two dates is set, and every
                     invoice raised before those columns existed has neither —
                     so nothing prints rather than a label with a dash after it. --}}
                <div class="label">Work period</div>
                <div class="meta-value">{{ $period }}</div>
            @endif
        </td>
    </tr>
    <tr>
        <td>
            <div class="muted small">Billed to</div>
            <div><strong>{{ $company?->legal_name ?? $company?->name ?? $customer?->name ?? '—' }}</strong></div>
            @if ($customer && $company)
                <div>{{ $customer->name }}@if ($customer->role), {{ $customer->role }}@endif</div>
            @endif
            @if ($company?->address)
                <div class="muted" style="white-space: pre-line">{{ $company->address }}</div>
            @endif
            @if ($company?->tax_number)
                <div class="muted small">
                    Tax number {{ $company->tax_number }}@if ($company->tax_office) · Vergi dairesi {{ $company->tax_office }}@endif
                </div>
            @endif
        </td>
        <td class="num">
            <div class="muted small">Amount due</div>
            <div style="font-size: 16pt;"><strong>{{ $total }}</strong></div>
            @if ($reporting)
                <div class="muted small">{{ $reporting['amount'] }} converted</div>
            @endif
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="width: 54%">Description</th>
            <th class="num" style="width: 12%">Quantity</th>
            <th class="num" style="width: 17%">Unit price</th>
            <th class="num" style="width: 17%">Amount</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($lines as $line)
            @php($tasks = $line->taskList())
            <tr>
                <td>
                    {{ $line->description }}
                    @if ($tasks !== [])
                        {{-- 🔴 The work the line covers, and no figure against
                             any of it. The price is the line's own, once: that
                             is how the owner bills and a column of amounts
                             beside these bullets would be a different document.
                             Nothing is emitted at all when the list is empty,
                             so a line without a scope reads exactly as it did
                             before the column existed. --}}
                        <table class="tasks">
                            @foreach ($tasks as $task)
                                <tr>
                                    <td class="dot">·</td>
                                    <td>{{ $task }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </td>
                <td class="num">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                <td class="num">{{ $lineUnitPrices[$line->id] }}</td>
                <td class="num">{{ $lineAmounts[$line->id] }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No lines on this invoice.</td></tr>
        @endforelse
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="muted">Subtotal</td>
        <td class="num">{{ $subtotal }}</td>
    </tr>
    @if ($exemptionCode)
        {{-- Zero-rated. The line is stated rather than omitted: an invoice with
             no tax line at all reads as an oversight, and the code is the thing
             a tax office looks for. --}}
        <tr>
            <td class="muted">KDV 0%</td>
            <td class="num">{{ $taxAmount }}</td>
        </tr>
    @elseif ((string) $invoice->tax_percent !== '0.000000')
        <tr>
            <td class="muted">Tax {{ rtrim(rtrim((string) $invoice->tax_percent, '0'), '.') }}%</td>
            <td class="num">{{ $taxAmount }}</td>
        </tr>
    @endif
    <tr class="grand">
        <td>Total</td>
        <td class="num">{{ $total }}</td>
    </tr>
</table>

<div class="provenance">
    <h3>How these figures were arrived at</h3>
    <dl>
        <dt>Invoice currency</dt>
        <dd>{{ $invoice->currency }}</dd>

        @if ($exemptionCode)
            {{-- The exemption belongs on the document above all else: this page
                 is the artefact a tax office reads, and a zero KDV line with no
                 stated reason is the one thing it will ask about. --}}
            <dt>KDV exemption</dt>
            <dd>
                Zero-rated under exemption code {{ $exemptionCode }}@if ($exemptionLabel) — {{ $exemptionLabel }}@endif.
                The issuer confirmed that each of the exemption's conditions applied to this invoice when it was
                raised.
            </dd>
        @endif

        @if ($reporting)
            <dt>Converted for reporting</dt>
            <dd>
                {{ $reporting['amount'] }} ({{ $reporting['currency'] }}) at
                {{ $reporting['rate'] }} on {{ $reporting['on'] }}
            </dd>
        @endif

        @if ($lira)
            {{-- Turkish tax procedure requires the lira equivalent at the TCMB
                 buying rate for the invoice date, and the liability for getting
                 it wrong sits with the issuer. So it is stated in full. --}}
            <dt>Lira equivalent</dt>
            <dd>{{ $lira['amount'] }}</dd>

            <dt>TCMB buying rate</dt>
            <dd>{{ $lira['rate'] }} on {{ $lira['on'] }} ({{ $lira['source'] }})</dd>

            @if ($lira['note'])
                <dt>Note</dt>
                <dd>{{ $lira['note'] }}</dd>
            @endif
        @endif

        @foreach ($chainPayments as $chain)
            <dt>Paid on chain</dt>
            <dd>
                {{ ucfirst($chain->chain) }} · {{ $chain->token_standard }}
                @if ($chain->confirmations)
                    · {{ $chain->confirmations }} confirmations
                @endif
                <div class="hash">{{ $chain->tx_hash }}</div>
            </dd>
        @endforeach
    </dl>
</div>

@if ($invoice->notes)
    <p class="small muted" style="margin-top: 8mm; white-space: pre-line">{{ $invoice->notes }}</p>
@endif

@if ($invoice->terms)
    <p class="small muted" style="white-space: pre-line">{{ $invoice->terms }}</p>
@endif

{{--
    The signature block.

    The image arrives already base64-encoded from `InvoiceDocument` and is null
    whenever there is no readable file — which is the state of a fresh install,
    since `public/img/signature.png` ships with nothing in it. That is not an
    error: what remains is a rule, the name and the date, which is a signature
    block a person can sign in ink. An empty rule with nothing under it would
    not be.
--}}
<table class="sign">
    <tr>
        <td></td>
        <td class="sign-block">
            @if ($signature['image'])
                <div><img src="{{ $signature['image'] }}" class="sign-img" alt=""></div>
            @endif
            <div class="sign-name"><strong>{{ $signature['name'] }}</strong></div>
            @if ($signature['date'])
                <div class="muted small">{{ $signature['date'] }}</div>
            @endif
        </td>
    </tr>
</table>

@if ($footer !== '')
    <div class="footer">{{ $footer }}</div>
@endif

</body>
</html>
