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
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.45; }
        h1 { font-size: 20pt; margin: 0 0 2mm; }
        .muted { color: #6b7280; }
        .small { font-size: 8.5pt; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; padding-bottom: 8mm; }
        .lines th { text-align: left; border-bottom: 1px solid #d1d5db; padding: 2mm 1mm; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        .lines td { padding: 2.5mm 1mm; border-bottom: 1px solid #f0f0f0; }
        .num { text-align: right; }
        .totals { margin-top: 4mm; width: 62mm; float: right; }
        .totals td { padding: 1.5mm 1mm; }
        .totals .grand td { border-top: 1.5px solid #1a1a1a; font-weight: bold; font-size: 12pt; padding-top: 2.5mm; }
        .provenance { clear: both; margin-top: 14mm; border: 1px solid #e5e7eb; padding: 4mm; font-size: 8.5pt; }
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
        <td class="num">
            <div><strong>Issued</strong> {{ $invoice->issued_on?->format('j F Y') ?? '—' }}</div>
            <div><strong>Due</strong> {{ $invoice->due_on?->format('j F Y') ?? '—' }}</div>
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
            <tr>
                <td>{{ $line->description }}</td>
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

</body>
</html>
