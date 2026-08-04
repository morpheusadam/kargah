# Handover — the Accounting wave, 6 August 2026

Two waves and a reconciliation pass, built by seven subagents under one coordinator. The module went
from "invoices exist" to something a freelancer can actually run a practice on — and, more
importantly, **from a book that could not be corrected to one that can be.**

Read `HANDOVER-2026-08-06.md` for the publishing work of the same day; this file is Accounting only.

---

## 1. What was wrong, and it was not what the brief said

**Nothing could be removed.** Three tables carried `deleted_at` and nothing ever wrote one.

| | create | edit | remove |
|---|---|---|---|
| Invoice | ✓ | ✓ | ✗ — only `void`, and a draft could not be deleted either |
| **Payment** | ✓ | ✗ | 🔴 **✗ — a mistyped payment was permanent** |
| Expense | ✓ | ✓ | ✗ — and nothing linked to the edit route either |
| Client | ✓ | — | ✗ — only archive |

The payment one is the worst of these. A freelancer who typed 12000 for 1200 had a permanently wrong
book with no recourse inside the application at all.

**And the expense edit page was unreachable.** `accounting.expense-edit` had existed since the routes
file was written and **nothing linked to it**. An expense could not be corrected, let alone deleted.

---

## 2. 🔴 The rule that governs everything here: the ledger is append-only

`LedgerEntry` **refuses `update()` and `delete()`** and throws telling you why. The correct operation
is `LedgerEntry::reverse($reason)`, which writes a contra entry with `reverses_id` pointing at the
original. `scopeStanding()` — what every balance is summed from — excludes reversed entries.

That infrastructure was complete, tested, and **wired to no user interface whatsoever**. This wave
wired it up. So "delete" in this module means three things that only count together:

1. reverse the ledger entry, with a reason,
2. soft-delete the document row,
3. **recompute the derived state** — the invoice's paid/part-paid status and `paid_at`.

Step 3 is the one that gets missed. `PaymentRecorder::statusFor()` is now the single definition of
"is this invoice paid", used by both recording and reversing; the old code had the comparison twice.

🔴 **If the ledger entry cannot be identified, `reverse()` throws and changes nothing.** A payment
carries no `ledger_entry_id`, so the entry is matched on type + reference + currency + date + applied
amount. A soft-deleted payment whose money still stands in every report is worse than the original
mistake — so the module refuses rather than doing two of the three.

---

## 3. What now exists

**Removal** — draft invoice delete (an **issued** one is voided, never deleted: a sequential number
is never reused, and the soft delete keeps the number reserved because `nextNumber()` and
`GenerateRecurringInvoices` both count `withTrashed()`); payment reversal; expense delete with ledger
reversal; client delete behind a hard guard.

**Reports** — aged receivables in five buckets per currency, "overdue worst first", cash-basis P&L
with a previous-period comparison, a tax summary (KDV / geçici vergi / stopaj), expenses by category.

**Dashboard** — outstanding receivables by age, a 12-month revenue-vs-expenses line chart, and a
revenue-by-client donut. First use of ApexCharts in the codebase.

**New** — estimates that convert to draft invoices; recurring expenses beside recurring invoices;
the KDV export-of-services exemption; a configurable reporting currency.

---

## 4. 🔴 The reporting currency, which is the decision with the longest reach

**It is now `config('accounting.reporting_currency')`, shipped default `TRY`.**

It used to be a `USD` default buried in `InvoiceIssuer::issue()`'s signature and a private constant on
the invoice builder, where nobody could see it and nothing overrode it. The lira reports were
therefore structurally near-empty — measured on the dev database at the time: **6 issued invoices, 1
with a lira figure.**

Three consequences to carry forward:

1. 🔴 **Changing it moves nothing that has already been issued.** `reporting_currency`,
   `reporting_rate` and `reporting_amount` are frozen per row at issue and are the record of what was
   believed then. **A mixed book — older rows in one currency, newer in another — is the normal
   state, not an error**, and every total groups by each row's own `reporting_currency`. This is
   mutation-tested: making `issue()` backfill fails
   `test_an_invoice_issued_before_the_setting_changed_still_reports_in_the_currency_it_froze`.
2. **Editing an existing expense keeps the currency that expense froze** and recomputes only the rate
   inside it. Always reading config would silently move a March expense from the dollar column to the
   lira one with nothing saying it moved.
3. **`turkishFigures()` is untouched and is not duplication.** With lira reporting, a domestic
   invoice computes lira twice from two *different* rates: the reporting figure uses the Frankfurter
   market rate, the legal figure uses the **TCMB buying** rate, and only the second is pinned by tax
   procedure. Merging them would let a mid-market rate onto a document where the law names TCMB's.

### The USDT hole, and why it is closed the way it is

`rateFor()` inverts a stored pair but will not chain two. With lira reporting, a **USDT** invoice had
no `USDT→TRY` rate and froze nulls — it fell out of every lira total.

The tempting fix is to multiply USDT/USD by USD/TRY inside `InvoiceIssuer`. That was **rejected**: a
composite of two rates from two providers is a number with no single source, inside a figure whose
whole job is to be defensible to somebody who did not compute it.

**Instead `CoinGecko::fetch()` now asks for the lira price directly** — `vs_currencies` is a list, so
it is the same request — and stores `USDT/TRY` as its own quote. One rate, one source, one day,
resolved directly by `rateFor()`. The module's rule survives intact: every frozen figure names the
rate that produced it.

⚠️ **The lira leg is optional.** If CoinGecko stops quoting it, the USD peg row — the reason that
source existed first — must keep working, so a missing leg is not an error and a USDT invoice simply
goes back to freezing nulls and being counted out loud. Both cases have a test.

---

## 5. 🔴 Tax: what is sourced, what is configuration, and what is deliberately absent

**Everything tax-related in this module is second-hand from `ACCOUNTING-RESEARCH.md` and was never
checked against the Gelir İdaresi Başkanlığı.** That is stated on the pages themselves, not only here.

- **KDV 20%** on professional services.
- **Export-of-services exemption, code 302.** 🔴 The software does **not** infer it from the client
  being foreign. Four cumulative conditions must hold and that is the freelancer's judgement, so it
  is an explicit, off-by-default choice with the four conditions as a checklist beside it, and the
  code is printed on the invoice document because that is the artefact a tax office reads.
  `kdv_exemption_code` is deliberately **not** in `Invoice::$fillable` — a zero-rating should not
  arrive by putting a key in an array. `create(['kdv_exemption_code' => '302'])` silently drops it;
  use `forceFill()`. The model says so.
- **Geçici vergi** — quarterly, first bracket, **15% for 2026**, threshold TRY 190,000. 🔴 Both live
  in `config/config.php` because Turkish brackets are revalued annually; a literal in a view goes
  silently wrong every January. A test proves the figure moves when the config does.
- 🔴 **Stopaj is computed only where it is sourced** — buyers flagged `companies.is_domestic`. The
  research **could not verify** whether the 20% withholding applies when the payer is a *foreign*
  client, which is Kargah's main case, so for a foreign buyer the page prints the count and the open
  question rather than a number. **Silence is better than a wrong tax number.**

---

## 6. What was verified, and how

🔴 **Seven invariants were mutation-tested** — broken deliberately, the test confirmed to fail, then
restored. Four of those were re-run independently by the main thread rather than taken on report.

| Invariant | Mutation | Result |
|---|---|---|
| A reversed payment restores the standing ledger | removed `$entry->reverse()` | `'0.000000'` expected, `'1500.000000'` actual |
| A referenced client cannot be deleted | `if (false)` on the blocker check | 4 tests failed, incl. "client whose only invoice was deleted" |
| Aged-receivables buckets | shifted a boundary one day | "30 days overdue landed in 31–60" |
| Issuing does not backfill history | made `issue()` update issued rows | "Nothing may backfill history" |
| Editing an expense does not move its currency | forced config lookup | "Editing an expense moved it to the currency the setting names today" |
| Recurring-expense idempotency | removed the conditional claim | "Failed asserting that 60 is identical to 1" |
| Estimate double-conversion | removed both guards | "One accepted quote just became two invoices" |

**A real bug was found in an unrelated module on the way.** The full suite failed on
`VaultTest::test_the_generator_produces_a_secret_of_the_requested_shape`. It was not flaky in the
usual sense: `Vault::generate()` put digits in the alphabet but never **guaranteed** one, and with
ambiguous characters excluded the digit alphabet is 8 of 78 — about a **3%** chance a 32-character
secret has no digit. The cost is not the test: a site demanding a digit rejects one generated secret
in thirty and nobody knows why. Each requested class is now guaranteed and the result shuffled with
Fisher-Yates over `random_int` — **not `str_shuffle()`, which uses a non-cryptographic generator and
would throw away the entropy the first half of the method exists to obtain.** 2,000 samples, zero
failures.

---

## 7. Open debts, in the order I would take them

1. 🔴 **The double-entry question.** `ACCOUNTING-RESEARCH.md` §1 recommends a double-entry engine
   under a single-entry UI — what Wave, Akaunting and QuickBooks Solopreneur all do. It was
   **deliberately not built**: it touches every model and every report and was not asked for. It is
   the one decision that gets more expensive the longer it waits.
2. **Nothing posts a `TYPE_EXPENSE` ledger entry.** Only the seeder and factory do. So expenses are
   absent from the ledger while invoice payments are in it, and any balance read from `standing()` is
   income-only. `⚡expense-edit::delete()` already reverses whatever it finds, so nothing has to
   change there when expenses do start posting.
3. **`payments` has no frozen reporting figure.** A collection is valued in lira at its *invoice's*
   issue-date rate, not the collection-date rate, so cash-in/cash-out per month could not be built
   and the P&L says it does not fold FX in. Needs one column written at the moment of payment.
4. **`AccountingDatabaseSeeder` mirrors `InvoiceIssuer`'s freezing logic** rather than calling it, and
   writes `reporting_currency => USD` for expenses. A freshly seeded demo no longer demonstrates the
   shipped default.
5. **Duplicated by necessity, worth extracting**: `Estimate::nextInvoiceNumber()` duplicates the
   private `⚡invoice-edit::nextNumber()`; `reportingFigures()` exists in the expense form and the
   recurring-expense command; the expense category list exists on two classes. All three are private
   to anonymous Livewire classes nothing can import — `InvoiceIssuer` and a service are the natural
   homes.
6. **`Estimate` and `EstimateLine` are in no morph alias**, which is why neither uses `Linkable` or
   `LogsActivity`. `MorphMap::enforce()` throws for an unaliased model used polymorphically — add the
   aliases *first* if anybody wants activity logging on estimates.
7. **Time tracking → billable hours → invoice** is the biggest thing the research ranks must-have
   that does not exist. Kargah already has projects and cards; this is the missing link between doing
   the work and billing it.
8. Credit notes; deposits/retainers; payment reminders.

---

## 8. 🔴 What nobody has seen

**No browser has rendered any of this.** There is no browser on this machine. Every "the page works"
in this wave means an HTTP 200 and an assertion against server-rendered markup.

That matters most for the charts, because they are the one thing that is **not** server-rendered:

- Nothing here executes JavaScript, so **nothing proves ApexCharts parses the options at all**. What
  is proved is that the script tag and the data reach the browser, and that the fallback tables
  render the right numbers without them.
- Every chart has a **server-rendered table underneath**, hidden only once the chart has actually
  rendered. A page served without the bundle shows the figures rather than an empty box. That was a
  requirement, not a nicety, precisely because of this gap.
- Series colours are **hex literals on purpose**: ApexCharts' `hexToRgba()` replaces any colour not
  starting with `#` with grey `#999999`, and this theme's `--color-primary` computes to `oklch(…)`.
  Both series would have come out the same grey on a chart that still rendered — nothing would have
  looked broken. This was read out of the shipped bundle, not assumed.
- No layout was checked at 375 / 768 / 1440px.

**The first thing the next session should do is open the dashboard and the reports page in a browser**
and look at them. Everything else in this handover has a test behind it; that does not.
