# 03 — Accounting: USD, TRY and USDT

Three currencies that behave differently. A dollar is a dollar. A lira moves several percent in a
week. A tether is a token on a chain, and which chain matters.

Getting this wrong is the one failure mode in Kargah that costs the owner real money, so this
document is stricter than the others.

---

## The rule everything follows from

**An issued invoice never changes its numbers.**

The exchange rate is captured at the moment of issue and frozen onto the row. A later rate move
must not retroactively alter what an invoice says. This is not an optimisation; it is the
difference between an accounting record and a spreadsheet that lies.

## Storing money

```
amount     decimal(20,6)      -- never float, never double
currency   varchar(10)        -- 'USD' | 'TRY' | 'USDT'
```

One column type for all three. USD and TRY need two decimals; **USDT needs six on both TRC-20 and
ERC-20** — the two networks agree, so there is no cross-chain precision mismatch to handle. Fiat
values are stored with trailing zeros (`1500.000000`) rather than varying the scale per currency,
which keeps every amount comparable in raw SQL.

`decimal(20,6)` holds up to 99,999,999,999,999.999999. That is enough.

**On SQLite it does not hold that.** SQLite has no DECIMAL storage class: the column gets NUMERIC
affinity and a non-integer is stored as an IEEE double, so the top of that range is silently
rounded — the maximum quoted above comes back as the integer 100000000000000. Measured, not
assumed; `NoFloatsInMoneyTest` pins both the safe range and the ceiling.

This is accepted rather than worked around. Integer minor units or money in a `varchar` would be
exact everywhere, and both cost `SUM()` and `ORDER BY` on every report to buy headroom no
freelance invoice will use.

The measured ceiling on SQLite is **fourteen significant digits**: 12,345,678.123456 round-trips
unchanged, 123,456,789.123456 comes back as …789.123460. The limit is PHP's `precision` ini
(14 by default) deciding how a float becomes a string, not the double, which holds nearly sixteen.
So the safe range is about ±99,999,999.999999 — a hundred million, at six decimals. MySQL and
MariaDB, the primary target, are exact throughout.

The rule that follows: **never do money arithmetic in SQL.** No `SUM(amount)` feeding a figure a
person reads. Totals are computed in PHP through `brick/money`, where the value is a decimal string
and the database is only ever storage. Aggregate SQL is fine for sorting and for approximate
dashboard figures, and must be labelled as such.

### Arithmetic

All money arithmetic goes through **`brick/money`**. Never `+`, never `round()`, never a float
anywhere in the path.

`brick/money` ships ISO 4217 currencies but not crypto, so USDT is defined once:

```php
// Modules/Accounting/app/Support/Currencies.php
public const USDT = new Currency('USDT', 0, 'Tether', 6);
```

`moneyphp/money` is the alternative and has a first-party `moneyphp/crypto-currencies` package, so
USDT arrives predefined. Either is defensible. `brick/money` is chosen for its PHP 8.2+ baseline,
immutability and explicit rounding control; the USDT definition is one object.

**Rounding is always explicit.** `RoundingMode::HALF_UP` for invoice totals, and every division
must state its mode. A library that forces you to say so is the point of using one.

## currencies

```
currencies
  code           varchar(10) pk       -- 'USD', 'TRY', 'USDT'
  name           varchar(60)
  symbol         varchar(8)           -- '$', '₺', '₮'
  minor_unit     tinyint              -- 2, 2, 6
  is_crypto      boolean
  is_active      boolean
  position       tinyint              -- display order
```

## Exchange rates: append-only history

```
exchange_rates
  id
  base_currency    varchar(10)        -- 'USD'
  quote_currency   varchar(10)        -- 'TRY'
  rate             decimal(20,6)
  rate_type        enum('market','tcmb_buy','tcmb_sell')
  source           varchar(30)        -- 'frankfurter' | 'tcmb_evds' | 'coingecko'
  as_of            date               -- the day this rate applies to
  fetched_at       datetime
  unique (base_currency, quote_currency, rate_type, as_of)
```

Rows are **never updated**. A correction is a new row. The unique key is on the business date, not
the fetch time, so re-running the fetch job is safe.

`rate_type` exists because the Turkish central bank publishes a buying rate and a selling rate, and
which one an invoice must use is a legal question, not a preference. Keeping them as distinct rows
avoids ever having to guess which one a stored number was.

### Sources

| Pair | Source | Cost | Why |
| --- | --- | --- | --- |
| USD/TRY — **invoice-facing** | **TCMB EVDS** | free, needs a key | The official rate. See below |
| USD/TRY — reference | Frankfurter | free, no key | ECB-derived, no quota, self-hostable |
| USDT/USD | CoinGecko | free tier: 10k calls/month | Volume-weighted across exchanges |

Fetch daily, on a schedule, into `exchange_rates`. Never fetch a rate during a request — a page
render must not depend on someone else's API being up.

```php
Schedule::command('accounting:fetch-rates')->dailyAt('09:00')->withoutOverlapping();
```

USDT should sit at 1.00. Store it anyway and surface deviation — a stablecoin that has depegged is
something the owner wants to know before invoicing in it.

## Two currencies on every row

Each monetary record carries its own currency **and** its value in the owner's reporting currency,
both frozen at creation.

```
invoices
  currency              varchar(10)      -- what the client pays in
  amount                decimal(20,6)
  reporting_currency    varchar(10)      -- the owner's chosen base
  reporting_rate        decimal(20,6)    -- frozen at issue
  reporting_amount      decimal(20,6)    -- frozen at issue
```

**Reporting currency** is what your own profit-and-loss is expressed in. For a freelancer billing
mostly in USD it is usually USD, even where invoices must also show a lira figure for legal
reasons. It is set once in settings and changing it does not rewrite history — old rows keep the
currency they were written in.

## Payments, and the gain or loss between

A payment may arrive in a different currency from the invoice: a USD invoice settled in USDT, or a
TRY invoice paid weeks later at a different rate.

```
payments
  id
  invoice_id            fk
  currency              varchar(10)
  amount                decimal(20,6)
  settlement_rate       decimal(20,6)    -- to the invoice's currency, frozen at payment
  fx_gain_loss          decimal(20,6)    -- (settlement_rate − issue_rate) × invoice amount
  method                varchar(30)      -- 'bank' | 'wise' | 'crypto' | 'cash'
  paid_at               datetime
  note                  text null
```

That `fx_gain_loss` column is the whole of realised foreign-exchange accounting for a one-person
business. Unrealised revaluation — restating still-open invoices at today's rate — is a **report**,
computed on demand. It is not written to any table, because nothing has actually happened yet.

## USDT payments

A crypto payment needs enough on the row to be verified by someone who does not trust you.

```
crypto_payments
  id
  payment_id        fk -> payments.id
  chain             enum('tron','ethereum')
  token_standard    enum('TRC-20','ERC-20')
  tx_hash           varchar(100) unique
  from_address      varchar(100)
  to_address        varchar(100)
  amount            decimal(20,6)        -- what actually arrived on chain
  network_fee       decimal(20,6) null
  block_number      bigint null
  confirmations     int
  status            enum('pending','confirmed','failed')
  verified_at       datetime null
```

**`chain` is not cosmetic.** USDT exists on both Tron and Ethereum with different addresses;
sending to the wrong network destroys the funds. The record must say which.

`amount` is stored separately from the invoice amount deliberately. Wallets round differently and
under- or over-payment by a few micro-units is normal. The delta is a business decision — write it
off or treat it as partial — not something to paper over by assuming they match.

**Confirmation:** Tron blocks land roughly every three seconds; treat 19+ confirmations as final.
Verify through **TronGrid** (official, 15 QPS with a free key) or **TronScan** (free key, richer
data). Poll from a queued job, never from a request.

## Invoicing a Turkish company

If the buyer is a **domestic Turkish company** and the invoice is in foreign currency, Turkish tax
procedure law requires the invoice to show the lira equivalent using the **TCMB buying rate
(döviz alış kuru) for the invoice date**, and the liability for getting it wrong sits with the
issuer. The invoice must carry the currency code, the rate type, the rate itself (to at most six
decimal places) and the computed TL figure.

If the buyer is **foreign**, none of that applies — no TL equivalent, no TCMB rate.

That is why `companies.is_domestic` exists, and why these columns are nullable:

```
invoices
  issue_rate_to_try      decimal(20,6) null   -- TCMB buy rate on issue date
  issue_rate_source      varchar(30)   null   -- 'tcmb_evds'
  try_equivalent         decimal(20,6) null   -- frozen at issue
```

Fill them when `company.is_domestic` is true. Leave them null otherwise.

### Flagged for an accountant — do not hard-code

Turkish thresholds are revised almost every year, usually by a year-end circular. Everything below
must live in **settings, not in code**, and be confirmed with a local muhasebeci before the owner
relies on it:

- e-Arşiv obligation above a per-sale amount; full e-Fatura above an annual revenue figure. The
  figures found in 2026 sources were 3,000 TL and 3,000,000 TL respectively, and a general
  invoice-issuance threshold of 12,000 TL — **reconfirm all three**.
- From 1 January 2027 essentially all invoices are expected to go through the GİB portal, which a
  self-hosted tool cannot satisfy alone. Kargah generates the document; submission is a separate
  question.
- Withholding (stopaj) when a Turkish company pays a foreign freelancer for professional services:
  the obligation falls on the Turkish payer, commonly cited at 20%, and is reduced or removed by a
  double-taxation treaty where one exists. The rate and treaty applicability are specific to the
  owner's residency.
- **USDT-denominated invoices to Turkish domestic customers are genuinely unresolved.** No
  authoritative Turkish ruling was found on how to compute the TL equivalent for a stablecoin. The
  workable approach is USD as an intermediate — TCMB USD/TRY × USDT/USD — but this needs an
  accountant's sign-off, not a developer's judgement.

Kargah must therefore **never block on a tax rule**. Show the computed figure, show which rate and
source produced it, and let the owner override with a note.

## Ledger

Not full double-entry. A one-person business does not need a chart of accounts, and the complexity
buys correctness guarantees it will not use.

What it does need is one append-only table so that a balance is read rather than recomputed by
summing three other tables and hoping:

```
ledger_entries
  id
  entry_type       varchar(30)     -- 'invoice_payment' | 'expense' | 'fx_conversion' | 'adjustment'
  reference_type, reference_id     -- morph, to the invoice/payment/expense
  currency         varchar(10)
  amount           decimal(20,6)   -- signed: positive in, negative out
  reporting_amount decimal(20,6)
  occurred_at      datetime
  created_at
```

Append only. Never updated, never deleted. A mistake is corrected by a reversing entry, which is
also the only way an audit trail stays true.

If real financial statements are ever needed, `academe/laravel-ledger` is the package to graduate
to — it is journal-centric, multi-currency, and treats FX clearing and realised gains as
first-class. Do not build toward it now.

## What the UI must always show

- The invoice's own currency, always, with its symbol.
- The reporting-currency figure alongside it, marked as converted.
- The rate used and its date, on the invoice detail — never only the converted number.
- For USDT: the chain, and the transaction hash as a link to the explorer.
- For a domestic Turkish invoice: the TCMB rate, its date, and the TL equivalent.

A number whose provenance is invisible is a number nobody can defend to an accountant.
