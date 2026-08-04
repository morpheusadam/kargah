# Double-entry under the single-entry UI — the migration plan

**Written 4 August 2026. No code was written this session; this document is the whole deliverable.**
Its job is to let the next session execute without rediscovering anything. Everything measured is
labelled measured, with the command beside it. Everything guessed is labelled unverified and prints
no number.

Owner decision this plan implements: build a double-entry engine underneath the existing
single-entry screens, the way Wave, Akaunting and QuickBooks Solopreneur do. `ACCOUNTING-RESEARCH.md`
§1 is the source of that recommendation. **The file is not in the repository** — it is at
`…\Temp\claude\c--Users-morph-Projects-Visa\97558b43-…\scratchpad\ACCOUNTING-RESEARCH.md`, a previous
session's scratchpad, and `git log --diff-filter=AD` finds no trace of it ever having been committed.
It is quoted here where it matters and disagreed with in §9, but it should be copied into
`project-guaid/` before it is lost.

---

## 0. What the code actually does today — measured, not assumed

Read this before anything else. Several things the handovers imply are not true of the shipped code.

### 0.1 The existing ledger is income-only, and mostly seeded

`Modules/Accounting/app/Models/LedgerEntry.php` is a single-entry, append-only table. It refuses
`update()` and `delete()` in `booted()` (lines 71–86), corrects by `reverse($reason)` writing a contra
row with `reverses_id` (lines 150–176), and `scopeStanding()` (lines 119–124) excludes both reversals
and reversed rows. That infrastructure is complete and correct.

**Exactly one production code path writes to it**: `PaymentRecorder::record()`
(`app/Services/PaymentRecorder.php` lines 84–96), which posts one `invoice_payment` row. Nothing
posts `TYPE_EXPENSE` except `AccountingDatabaseSeeder::seedExpenses()` (line 420) and
`LedgerEntryFactory`. Nothing posts anything at invoice issue. So a balance read from `standing()`
today is **income only, and only the income that has been collected** — there is no receivable in it
at all.

Measured on the owner's real database (`database/database.sqlite`, port 8123):

```
invoices=7  issued=6  payments=3  expenses=8  ledger=11  estimates=0  recurring=0
payments trashed=0   expenses trashed=0   invoices trashed=0   voided=0
ledger reversal entries=0
```

The 11 ledger rows are 3 `invoice_payment` and 8 `expense` — and all 8 expense rows were written by
the seeder, not by any user action, because no user path writes them. **The backfill in §5 is
therefore working over 6 invoices, 3 payments and 8 expenses.** It can afford to be slow and careful.

### 0.2 The whole book froze `USD`, while the config says `TRY`

```
INV-0038 USD paid     rep=USD/1200        try=NULL
INV-0039 USD paid     rep=USD/5150        try=NULL
INV-0040 USD overdue  rep=USD/980         try=NULL
INV-0041 USD sent     rep=USD/2400        try=NULL
INV-0042 TRY sent     rep=USD/1588.8312   try=NULL
INV-0043 USDT paid    rep=USD/2749.78     try=111534.010815
INV-0044 USD draft    rep=NULL/NULL       try=NULL
```

All eight expenses likewise froze `reporting_currency = USD`. `config('accounting.reporting_currency')`
ships `TRY` (`Modules/Accounting/config/config.php` line 68). Nothing is wrong here — this is rule 3
working exactly as designed: these rows were issued before the setting changed and nothing backfills
them. But it means **the mixed book is not hypothetical on this install; it is the entire install**,
and the backfill must reproduce it faithfully.

### 0.3 A consequence worth knowing before planning any report work

Measured through the bound contracts:

```
expensesByMonth  counted=0  excluded=8
revenueByMonth   counted=2  excluded=4
revenueByClient  counted=2  excluded=4
```

`ExpenseReader::expensesByMonth()` returns **zero for every month on the owner's real data**. Its
`lira()` (lines 135–146) requires `reporting_currency === TRY`; every expense froze `USD`. The
dashboard's expense series is empty and always has been. `revenueByMonth()` finds 2 of 6 — INV-0042
(raised in lira) and INV-0043 (froze `try_equivalent`).

🔴 **Double-entry does not fix this.** The gap is in the frozen figures on the documents, not in how
they are aggregated. A journal built from those same figures will be equally empty in lira. Fixing it
is a separate decision (re-freeze history at the rates in force then, or accept the gap) and is
deliberately not in this plan — see §9.

### 0.4 Voiding an invoice touches nothing

`⚡invoice-show.blade.php::voidInvoice()` (lines 416–441) sets `status` and `voided_at` and stops.
There is no guard against voiding an invoice that has standing payments. Today that is harmless
because nothing derives a balance from the ledger. After §4(d) it stops being harmless.

### 0.5 A payment cannot name the ledger row it wrote

`PaymentRecorder::entryFor()` (lines 251–264) matches on type + reference + currency + date + applied
amount, and its own docblock admits "two identical payments against one invoice on one day are
genuinely indistinguishable". `payments` has no `ledger_entry_id`. §2 closes this with one nullable
column, which is the cheapest high-value change in the whole plan.

---

## 1. The chart of accounts

### 1.1 The accounts

Twelve is too many. This is nine, and one of them is only for the backfill.

| Code | Name | Type | Normal | What posts to it |
|---|---|---|---|---|
| `1000` | Cash and bank | asset | debit | payments in, expenses out |
| `1100` | Accounts receivable | asset | debit | invoice issued (Dr), payment received (Cr) |
| `1900` | Currency exchange | asset | debit | both legs of a cross-currency settlement — see §4(b) |
| `2100` | KDV payable | liability | credit | `invoices.tax_amount` at issue |
| `3000` | Opening balances | equity | credit | the backfill only, and on this install never — see §5.2 |
| `4000` | Service income | income | credit | `invoices.subtotal` at issue |
| `5000` | Business expenses | expense | debit | every `expenses` row |
| `9000` | Suspense | — | debit | nothing. Exists so a future correction has somewhere legal to land, and a standing balance in it is a defect by definition |

Deliberately absent, each with a reason, because an empty account on a trial balance is a question
somebody has to answer:

- **No `2000 Accounts payable`.** The `expenses` table has no unpaid state — no `due_on`, no
  `paid_at`, no vendor entity. An expense is recorded as already spent. AP would be an account nothing
  ever credits. Add it the day a `bills` concept exists, not before.
- **No `3100 Owner's drawings`.** Nothing in the schema records the owner taking money out.
- **No stopaj / withheld-tax account.** Posting withheld income tax requires deciding whether Income
  Tax Law Art. 94 reaches a *foreign* payer, which is Kargah's main case. `config.php` lines 164–177
  records that this **could not be verified**, and the reports page prints the open question instead
  of a number. A journal account would be the same wrong number in a more authoritative place.
  **Unverified. Print no number.**
- **No FX gain/loss account.** This is a deliberate departure from `ACCOUNTING-RESEARCH.md` §6;
  see §9.2.
- **No account per expense category.** `expenses.category` is a nullable free-text `string(60)`; the
  six values on disk are Hardware, Hosting, Domains, Other, Email, Software, while
  `RecurringExpense::CATEGORIES` offers eight and `⚡expense-edit::CATEGORIES` holds a second copy of
  the list (both files say so). An account per category would make the chart of accounts editable
  through a free-text field — precisely what §1 of the research says the freelancer tier must hide.
  Category detail stays on the document, where `expensesByCategory` already reads it. If a
  category-level trial balance is ever wanted, the correct order is: close the category list first,
  *then* add accounts.

### 1.2 One cash account, not one per currency

`ACCOUNTING-RESEARCH.md` §1 recommends "Cash/Bank per currency". **Reject that.** Every money row in
this module already carries its own `currency` column and every total is grouped by it; that is the
module's oldest rule. Baking the currency into the account code duplicates it, and then `1000-USD`
and `1000-TRY` are two accounts that can silently disagree with the `currency` column on the same
line. With one account and a currency column, the invariant in §7.1 — *debits equal credits per
currency* — is the natural expression, and a per-currency cash position is a group-by, not a
different chart.

### 1.3 Where the list lives, and why

**A table `ledger_accounts` (`code` as string primary key), seeded from a single PHP class
`Modules\Accounting\Support\Accounts` which is the authority, with a test asserting parity.**

The three options and why the others lose:

- **Config array only.** Cheapest, but `journal_lines.account_code` could then hold anything, and
  there is no foreign key. The ledger is append-only; an account code typo is permanent.
- **PHP class only.** Same problem, plus it repeats the `Currencies` mistake below.
- **Table only.** A user-editable chart by the back door, and the seeder becomes the definition —
  which is where a business rule goes to be forgotten.

🔴 **The precedent that decides it.** `currencies` is already a seeded table with `code` as primary
key (`2026_03_01_000001_create_currencies_table.php`). Nothing in the money path reads it: the
authority is `Modules\Accounting\Support\Currencies::supported()`, a hardcoded array. Grep confirms
`Currency::` appears only in the factory, the seeder, and one test. **Kargah already has one
reference table the code ignores; do not build a second.** The FK from `journal_lines.account_code`
is what makes the table load-bearing rather than decorative, and the parity test (§7.8) is what stops
the class and the table drifting.

### 1.4 How the freelancer never sees a debit

They never do, because nothing in this plan adds a screen. There is no chart-of-accounts editor, no
journal-entry form, no account picker. `⚡invoices`, `⚡invoice-edit`, `⚡invoice-show`, `⚡expenses`,
`⚡expense-edit`, `⚡estimates` and `⚡recurring` are untouched by stages 0–7. The words "debit" and
"credit" appear in exactly two places: model docblocks, and the eventual trial balance page — which
is **not in this plan**.

---

## 2. The schema

### 2.1 `ledger_accounts`

```
code                string(10)   primary key
name                string(80)   not null
type                string(20)   not null   asset|liability|equity|income|expense
normal_balance      string(6)    not null   debit|credit
position            unsignedTinyInteger default 0
is_active           boolean      default true
timestamps
index (type, position)
```

`code` as the primary key rather than an auto-increment id, matching `currencies`: the code is what a
human reads on a report and what survives a reseed.

### 2.2 `journal_entries`

```
id                  id
kind                string(30)   not null   invoice_issued|payment|expense|void|reversal|opening|adjustment
reference_type      string(60)   nullable   morph alias, never a class name
reference_id        unsignedBigInteger nullable
group_uuid          uuid         nullable   ties the two entries of a cross-currency settlement
description         string(255)  nullable
reverses_id         foreignId    nullable  constrained('journal_entries') nullOnDelete
occurred_at         timestamp    not null
created_by          foreignId    nullable  constrained('users') nullOnDelete
created_at          timestamp    nullable

index (reference_type, reference_id)
index (kind, occurred_at)
index (occurred_at)
index (group_uuid)
```

No `updated_at` and no `deleted_at`, exactly as `ledger_entries` has none, and for the same reason
its migration docblock gives: a row here is never edited and never removed.

`reference_type`/`reference_id` is a **plain morph, not a foreign key** — deliberately copied from
`ledger_entries`, whose docblock explains it (lines 25–27): deleting an invoice must not delete the
entries recording what was received against it. The trail outlives the document.

`reverses_id` keeps `ledger_entries`' semantics unchanged: a contra entry points at the one it undoes,
and `scopeStanding()` is `where('kind','!=','reversal')->whereDoesntHave('reversal')`, character for
character the scope that exists today.

### 2.3 `journal_lines`

```
id                  id
journal_entry_id    foreignId    constrained('journal_entries') cascadeOnDelete
account_code        string(10)   not null  constrained: foreign('account_code')
                                           ->references('code')->on('ledger_accounts')->restrictOnDelete()
currency            string(10)   not null
debit               decimal(20,6) not null default 0
credit              decimal(20,6) not null default 0
reporting_currency  string(10)    nullable
reporting_rate      decimal(20,6) nullable
reporting_amount    decimal(20,6) nullable
position            unsignedTinyInteger default 0
created_at          timestamp     nullable

index (journal_entry_id, position)
index (account_code, currency)
index (account_code, currency, journal_entry_id)
```

**Two columns, not one signed column.** `ledger_entries.amount` is signed and that was right for a
single-entry table where the sign *is* the direction. In a double-entry table the sign convention
depends on the account's normal balance, which is exactly the knowledge the single-entry model failed
to encode — a signed column would push it back into the reader's head. Two columns also make the §7.1
invariant read as the sentence an accountant already knows.

`cascadeOnDelete` on `journal_entry_id` is safe and is not a hole: the entry can never be deleted
(§2.6), so the cascade never fires. It exists so the FK is complete.

`restrictOnDelete` on `account_code`: an account with history cannot be removed. This is enforced —
`PRAGMA foreign_keys` returns **1**, measured.

Optional and recommended, but state it as optional so the owner can drop it: a raw
`CHECK ((debit = 0) <> (credit = 0))` added by driver-conditional SQL. SQLite and MySQL both honour
it. The model-level guard in §2.6 is what actually protects; the CHECK is belt and braces.

### 2.4 One new column on an existing table

```
payments.journal_entry_group_id   uuid  nullable  index
```

**Adding a column is safe on SQLite** — `ALTER TABLE … ADD COLUMN`, no table rebuild, no cascade.

This closes §0.5. `PaymentRecorder::reverse()` currently identifies the entry to reverse by matching
five attributes and admits it can be ambiguous; with this column it names it. Backfilled payments have
`null` here, so **the matching heuristic stays as the fallback and must not be deleted** — it is the
only thing that can find the entry for a payment recorded before this column existed.

### 2.5 🔴 Where SQLite bites, and what it costs

**Adding is safe. Dropping is not, and dropping inside a transaction can silently delete rows.**

Kargah has already been bitten by this and the fix is on disk to copy from:
`Modules/Project/database/migrations/2026_08_02_000001_create_card_placements_table.php`, lines 22–35
of its docblock. The mechanism, restated because the next session must not have to rediscover it:

1. SQLite's own `DROP COLUMN` refuses a column that carries a foreign key or sits in a composite
   index. Laravel therefore **recreates the whole table and copies the rows**.
2. Recreating means dropping the old table, and *that* fires every `ON DELETE CASCADE` pointing at it.
3. Laravel turns foreign keys off around the rebuild. That is enough when migrations run normally.
4. **But `PRAGMA foreign_keys` is a documented no-op inside an open transaction.** A test that wraps
   its migrations, or a `DB::transaction` around a data migration, silently takes the child rows with
   it. That was measured, not assumed, in the placements work.

**The rule for this plan:** every migration in stages 0–8 only *creates* tables and *adds* columns.
Nothing drops anything. If a later revision ever needs to drop a column from `payments`, `invoices` or
`expenses`, it must copy the affected rows into a staging table with **no foreign keys of its own**,
do the drop, then copy back — the exact three-step shape `card_placements` uses.

The `down()` methods do drop the new tables, which is correct: nothing has a foreign key *into*
`journal_entries` or `journal_lines` except each other, so the cascade has nowhere to reach.
`ledger_accounts` must be dropped **after** `journal_lines`, or the restrict fires.

### 2.6 The models

`Modules\Accounting\Models\JournalEntry` and `JournalLine`, both with:

- `public const UPDATED_AT = null;`
- `booted()` throwing `LogicException` on `updating` and `deleting`, with the same "a removed row is a
  gap; a reversing entry is a record" sentence `LedgerEntry` uses. Copy the wording; a person hitting
  it five months from now should recognise it.
- `casts()`: `debit`, `credit`, `reporting_rate`, `reporting_amount` → `decimal:6`. **Never float.**
- `JournalLine::booted()` additionally throws on `creating` when both `debit` and `credit` are
  non-zero, or both are zero.
- `JournalEntry::reverse(?string $reason)`: creates a `kind = reversal` entry with `reverses_id` set,
  copying every line with **`debit` and `credit` swapped**, not negated. A negative debit is not a
  thing an accountant reads, and it would violate the CHECK. Reporting columns are copied with
  `reporting_amount` negated through `BigDecimal::negated()` — same as `LedgerEntry::reverse()` lines
  159–161, and for the same reason stated there: the attribute is a decimal *string* and unary minus
  would cast to float first.
- `JournalEntry::reverse()` throws `DomainException` if already reversed, same as
  `LedgerEntry::reverse()` lines 152–157.

Morph aliases in `AccountingServiceProvider::boot()` (currently lines 64–70): add
`'journal_entry' => JournalEntry::class` and `'journal_line' => JournalLine::class`.
`MorphMap::enforce()` throws for an unaliased model used polymorphically, so this must land in the
same commit as the models.

### 2.7 The float guard

`tests/Feature/NoFloatsInMoneyTest.php::monetaryColumns()` (lines 44–55) must gain:

```php
'journal_lines' => ['debit', 'credit', 'reporting_rate', 'reporting_amount'],
```

and `test_every_monetary_attribute_is_cast_to_a_decimal_string()` (lines 203–226) must gain
`JournalLine::class => ['debit', 'credit']`.

🔴 **Nothing in this plan may `SUM()` money in SQL.** Every balance is `journal_lines` rows fetched
into PHP and added through `Modules\Accounting\Support\Money`, grouped by currency. On SQLite a
`decimal` column has NUMERIC affinity and a non-integer is stored as an IEEE double
(`DECISIONS.md` "Phase 3", measured: the spec's stated maximum comes back as an integer). Counting
rows in SQL is fine; adding them is not. The `account_code, currency` index exists so the *selection*
is cheap, not so the sum can move into the database.

---

## 3. What happens to `ledger_entries`

**Retired in place. Not migrated, not wrapped, not dropped.**

Concretely:

1. The table stays. The 11 rows stay. `LedgerEntry` stays, with its `updating`/`deleting` guards
   intact.
2. At **stage 6** the model gains a third guard, `creating`, throwing:
   *"Ledger entry cannot be created. This table was closed on <date> when the journal replaced it —
   post to `JournalPoster` instead. The rows here are history and stay readable."*
3. `reverses_id` and `scopeStanding()` are **unchanged and still work**, for the historical rows only.
   Nothing in the new engine reads them. `LedgerEntry::reverse()` stays callable, because a historical
   row could in principle still need correcting; if that ever happens the correction must be posted to
   the journal too, and the closing docblock says so.
4. Nothing reads it after stage 7. It is kept because the module's founding docblock says the trail
   outlives the document, and a table deleted to tidy up is a trail deleted to tidy up.

Why not the alternatives:

- **Migrate the rows one-for-one and drop the table.** Rejected on both halves. The rows are the wrong
  source: 8 of 11 came from the seeder rather than from a user action, and there is no row at all for
  the receivable on any of the 6 issued invoices. Backfilling from the *documents* (§5) produces the
  complete book; copying the old ledger produces the incomplete one, permanently. And dropping the
  table destroys evidence for the sake of tidiness.
- **Wrap it as a view over `journal_lines`.** Rejected. SQLite views are read-only, the columns do not
  map (one signed `amount` against `debit`/`credit`, and no account concept at all), and
  `scopeStanding()`'s `whereDoesntHave('reversal')` needs a real self-referencing foreign key, which a
  view does not have.

The one thing that *does* move: `⚡expense-edit.blade.php::entriesFor()` (line 531) currently reads
`LedgerEntry::query()->forReference($expense)->standing()`. At stage 6 it reads the journal instead.
Its surrounding `delete()` already reverses "whatever it finds" and handles finding nothing
(lines 468–515, and the docblock at 441–460 explains why it must), so the shape survives the swap —
only the model name changes.

---

## 4. The posting rules, event by event

Every posting goes through **one service, `Modules\Accounting\Services\JournalPoster`**, called
explicitly. Not a model observer. An observer would fire inside factories, seeders and every test
fixture — and `AccountingDatabaseSeeder` already writes its own ledger rows with `firstOrCreate`, so
an observer would double them. It also could not hold a *stated* rate, and every frozen figure in this
module must name the rate that produced it (`Money::convert()`'s docblock, lines 136–142: "there is no
overload that looks the rate up"). The cost of explicit calls is that a new writer added later will
silently not post; §7.9 is the test that catches that.

**Frozen reporting figures on lines follow one rule and it is not the obvious one.** A line's
`reporting_amount` is its **share of the document's frozen reporting amount**, in the proportion the
line is of the document total — never `line × reporting_rate`. This is not fastidiousness: it is the
lesson already learned in `⚡reports.blade.php::shareInLira()` (docblock at lines 459–471). For a USDT
invoice the frozen lira equivalent was computed through a USD bridge, so `issue_rate_to_try` is the
lira price of a *dollar*, not of a tether, and multiplying by it overstates by the whole bridge. A
share is exact whichever route froze the figure, and it can never disagree with the number printed on
the document.

If the document froze no reporting figure, **every line gets null reporting columns.** Never a
fallback rate, never today's rate.

### (a) Invoice issued — `InvoiceIssuer::issue()`

Today: posts nothing. New, one entry, `kind = invoice_issued`, `occurred_at = issued_on`,
`reference = invoice`, currency = `invoices.currency`:

| Account | Dr | Cr |
|---|---|---|
| `1100` Accounts receivable | `total` | |
| `4000` Service income | | `subtotal` |
| `2100` KDV payable | | `tax_amount` |

The KDV line is **omitted entirely when `tax_amount` is zero** — which is every invoice zero-rated
under exemption code 302. A zero line is noise, and `recalculate()` already forces the rate as well as
the amount to zero for an exempt invoice (`InvoiceIssuer` lines 76–98), so there is nothing to record.

🔴 This is the moment the book becomes **accrual**. AR exists where it never did. That is not a
regression against the cash-basis reports page — the cash-basis P&L becomes a *filter* on entries that
touch `1000 Cash`, which is strictly more honest than today's derivation. See §6.5.

⚠️ **Unverified: whether KDV for a serbest meslek erbabı is assessed on issue or on collection.** The
SMM is issued on collection (`ACCOUNTING-RESEARCH.md` §4.3), which suggests the KDV liability may
arise then too. This plan posts it at issue because that is the arithmetic the invoice already states,
and flags that the *report* over `2100` may need restricting to collected invoices. **No number is
printed either way until a mali müşavir answers it.**

### (b) Payment received — `PaymentRecorder::record()`

Two shapes, and which one applies is decided by `$payment->currency === $invoice->currency`.

**Same currency** — one entry, `kind = payment`, `occurred_at = paid_at`, `reference = invoice`:

| Account | Dr | Cr |
|---|---|---|
| `1000` Cash and bank | `applied_amount` | |
| `1100` Accounts receivable | | `applied_amount` |

**Cross-currency** (a USDT payment against a USD invoice) — **two entries sharing one `group_uuid`**,
because a single entry cannot balance in two currencies and rule 2 forbids inventing a figure that
would let it:

Entry 1, currency = payment currency:

| Account | Dr | Cr |
|---|---|---|
| `1000` Cash and bank | `payments.amount` | |
| `1900` Currency exchange | | `payments.amount` |

Entry 2, currency = invoice currency:

| Account | Dr | Cr |
|---|---|---|
| `1900` Currency exchange | `applied_amount` | |
| `1100` Accounts receivable | | `applied_amount` |

`1900` therefore accumulates a standing balance in each currency that never nets to zero, and **that is
correct, not a defect**: netting them would require applying a rate, which is the thing the module
refuses to do silently. The pair is traceable through `group_uuid`, and `payments.settlement_rate` on
the row is the rate that connects them.

`payments.fx_gain_loss` posts **no journal line**. It is the difference between what the payment
currency was worth at issue and at settlement, in the invoice's currency — a figure whose counterparty
was never booked, because the "expected" amount was never a journal entry. It stays a memo on the
payment row where `⚡reports::realisedFx()` already reads it correctly. See §9.2 for the argument
against the research on this point.

`payments.journal_entry_group_id` is written here, with the `group_uuid` (same-currency payments get a
group of one).

### (c) Payment reversed — `PaymentRecorder::reverse()`

Reverse **every entry in the group**, each by `JournalEntry::reverse($reason)`, in one transaction with
the soft-delete and the `settleStatus()` recompute. The existing three-things-or-nothing rule
(docblock lines 145–178) is unchanged and is the reason this is one transaction:

1. reverse the journal entries (never delete),
2. soft-delete the `payments` row,
3. recompute the invoice's derived state through `statusFor()`.

Resolution order for finding the entries: `journal_entry_group_id` first; if null (a backfilled or
pre-column payment), fall back to the existing five-attribute match adapted to `journal_entries`.
🔴 **If neither finds them, nothing happens at all** — the current `DomainException` text at lines
202–208 is already exactly right and should be reused verbatim.

### (d) Invoice voided — `⚡invoice-show::voidInvoice()`

New behaviour, and it is a behaviour change: **void refuses when the invoice has standing payments.**

If no payments stand: reverse the `invoice_issued` entry (which by swap becomes Cr `1100`, Dr `4000`,
Dr `2100`), then set `status`/`voided_at` as today.

If payments stand: refuse, with a sentence naming what to do —
*"INV-0040 has $1,200.00 of payments recorded against it. Reverse them first; voiding now would leave
the cash standing against no receivable."*

This follows the module's established rule of refusing rather than doing two of three
(`PaymentRecorder::reverse()`, and `⚡expense-edit::delete()`). Today voiding a paid invoice is
permitted and harmless only because nothing derives a balance from the ledger. After (a) it stops
being harmless.

**Cost:** `tests/Feature/InvoicePagesTest.php` (29 tests) — any void test whose fixture has a payment
will need the payment reversed first, or the fixture changed. Expect 2–3 assertions to move.

### (e) Draft invoice deleted

Nothing. A draft was never issued, so it posted nothing. Say this in the docblock at the delete site,
because it is the obvious place for somebody to add a posting that would then reverse an entry that
never existed.

### (f) Expense recorded — `⚡expense-edit::save()` and `GenerateRecurringExpenses`

Today: posts nothing (this is the hole). New, one entry, `kind = expense`, `occurred_at = spent_on`,
`reference = expense`, currency = `expenses.currency`:

| Account | Dr | Cr |
|---|---|---|
| `5000` Business expenses | `amount` | |
| `1000` Cash and bank | | `amount` |

**The assumption, stated because it is an assumption:** crediting Cash asserts the expense was paid
when it was incurred. That is the only reading the schema supports — `expenses` has no `paid_at` and
no unpaid state. If the owner ever records a supplier bill they have not paid, this posting is wrong
and `2000 Accounts payable` becomes necessary.

🔴 **What closing this hole costs.** Today `standing()` is income-only, so no figure anywhere is
computed from an expense-inclusive ledger balance. After this, a journal balance is income *minus*
expenses. The blast radius is small in production — nothing in the UI reads a ledger balance; the
reports page reads `invoices`, `payments` and `expenses` directly. The radius in the suite is not
small: `tests/Feature/AccountingModelTest.php` (22 tests) and
`tests/Feature/RecurringExpensesTest.php` (17 tests) both assert on ledger-entry counts and will need
journal-entry counts added or substituted. Budget ~10 assertions.

Two writers, both must call the poster: `⚡expense-edit.blade.php` (~line 387) and
`GenerateRecurringExpenses.php` (~line 198). They already duplicate `reportingFigures()` between them
(the command's docblock at line 265 says so). **Do not fix that duplication in this work** — it is a
separate refactor with its own risk, and it is item 5 on the handover's open-debts list.

### (g) Expense deleted — `⚡expense-edit::delete()`

Reverse the expense's standing journal entry, then soft-delete, in one transaction — the shape already
written at lines 492–503. Only the model changes. No new column on `expenses`: there is exactly one
entry per expense and `forReference()` finds it unambiguously, unlike the payment case.

### (h) Recurring invoice generated — `GenerateRecurringInvoices`

**Nothing.** It produces a *draft*, and a draft posts nothing. `DECISIONS.md` Phase 3 records why it
produces drafts: "Issuing freezes an exchange rate onto a legal document. That is a decision a person
makes, not one a cron job makes at 3am against whatever rate happened to be current." The posting
happens when a person later issues the draft, through (a). State this explicitly at the call site.

### (i) Recurring expense generated — `GenerateRecurringExpenses`

Posts, exactly as (f). This is the difference between the two commands and it is not an inconsistency:
a recurring *expense* records money that has already left, a recurring *invoice* proposes money that
has not yet been asked for.

### (j) Estimate converted to invoice — `Estimate::convertToInvoice()`

**Nothing.** It produces a draft (line 302: `'status' => 'draft'`, and the docblock at line 309 notes
`isIssued()` stays false). Same as (h).

---

## 5. The backfill

### 5.1 It is a command, not a migration

`php artisan accounting:backfill-journal [--dry-run]`.

A migration that writes money runs inside a transaction, on a machine nobody is watching, with no
chance to inspect the result before it commits. A command can dry-run, can print the reconciliation
in §5.5, and can be re-run. The module's own precedent is commands for anything that touches money:
`accounting:fetch-rates`, `accounting:generate-recurring-invoices`,
`accounting:generate-recurring-expenses`.

Idempotent by `firstOrCreate` keyed on `(kind, reference_type, reference_id, occurred_at)` — the same
key `AccountingDatabaseSeeder::seedLedgerEntry()` uses, and its docblock (lines 436–443) explains why
`firstOrCreate` rather than `updateOrCreate`: the ledger refuses updates, so match-or-insert is the
only idempotent write available.

### 5.2 The opening balance is **zero**, deliberately

**No row anywhere in the schema records a bank balance.** There is no `accounts`, no `bank_accounts`,
no opening figure on anything. Deriving one would mean inventing a number.

So the backfill posts **no opening entry at all**, and `3000 Opening balances` stays empty on this
install. The consequence must be written into the docblock of anything that reads `1000`:

> `1000 Cash and bank` is the sum of cash movements Kargah knows about. **It is not the balance of a
> bank account.** Nothing here was opened with a starting figure, because nothing in the schema
> records one.

If the owner wants a real cash position, the missing feature is a one-row opening-balance form posting
Dr `1000` / Cr `3000` per currency at a date they choose. That is a separate, small piece of work and
it is **not in this plan**, because the number it needs comes from the owner's bank, not from the
database.

### 5.3 The order, and what it reads from

Documents, **never the old `ledger_entries` table** — see §3 for why.

1. **Issued, unvoided invoices**, ordered by `issued_on` then `id`. Post (a) using the invoice's own
   frozen `reporting_currency`, `reporting_rate`, `reporting_amount`, apportioned by share.
   `Invoice::issued()` is `whereNotNull('sent_at')->whereNull('voided_at')` — use the scope, not a
   second definition. Drafts are skipped (INV-0044 on this install).
2. **Voided invoices** (none on this install, measured): post the issue entry at `issued_on`, then its
   reversal at `voided_at`, so the trail shows both. Skip if `voided_at` is null.
3. **Payments, `withTrashed()`**, ordered by `paid_at` then `id`. Post (b) at `paid_at`. For a trashed
   payment, additionally post the reversal, dated from the matching `LedgerEntry` contra row's
   `occurred_at` where one can be found, and from `deleted_at` where it cannot — with the fallback
   named in the entry's `description` so the substitution is visible rather than silent.
   Measured: 3 payments, 0 trashed, so this branch is untested by real data and needs a fixture.
4. **Expenses, `withTrashed()`**, ordered by `spent_on` then `id`. Post (f) at `spent_on`; for a
   trashed expense, additionally the reversal at `deleted_at`. Measured: 8 expenses, 0 trashed.
5. **Nothing else.** Estimates and recurring schedules post nothing (§4 h, j), and both are empty
   anyway.

### 5.4 🔴 History is never re-converted

The backfill reads `$invoice->reporting_currency` and `$invoice->reporting_amount`. It **must not**
call `InvoiceIssuer::reportingCurrency()`, must not call `ExchangeRates::rateFor()`, and must not read
`config('accounting.reporting_currency')` for any historical row.

On this install that rule is not theoretical: **all six issued invoices froze `USD` while the config
says `TRY`** (§0.2). A backfill that read the config would move every one of them into the lira column
and last March would then move every time the lira does. The mutation test in §7.3 exists specifically
to prove this rule holds.

### 5.5 What the command prints, and when it stops

For each currency, three columns: the old `LedgerEntry::standing()` total, the new journal's `1000`
total, and the difference. They should agree, because both are built from the same payments and the
same expenses.

Expected on this install (to be confirmed by the dry run, not asserted here): the 3 `invoice_payment`
rows and the 8 `expense` rows should reconcile against the new cash lines in USD and USDT. If any
currency differs, **the command refuses to write and prints the offending rows**. A backfill that
silently reconciles is a backfill nobody can check.

It also prints, out loud and by count:

- issued invoices with **no frozen reporting figure** — their lines got null reporting columns.
  Measured expectation on this install: 0, since all six froze USD. A dry run will confirm.
- payments whose reversal date had to fall back to `deleted_at`.
- any expense with a `category` not in `RecurringExpense::CATEGORIES` — harmless, since §1.1 uses one
  expense account, but worth surfacing.

### 5.6 A document that froze no reporting figure at all

Its lines carry `reporting_currency = null`, `reporting_rate = null`, `reporting_amount = null`, and
the count above names it. It still posts a perfectly valid entry in its own currency, and it still
balances in that currency — the reporting columns are memo (§7.1). It contributes nothing to a
reporting-currency total, which is the same treatment every reader on the reports page already gives
it, phrased as "counted out loud rather than converted".

---

## 6. Every report that has to change

The headline recommendation first, because it decides the size of everything below:

🔴 **Do not change the shapes in `Modules/Accounting/app/Contracts/InvoiceReader.php` or
`ExpenseReader.php`.** They are the seam. `resources/views/pages/⚡dashboard.blade.php` calls only
through the container-bound contracts (lines 187, 223, 241–242, 288, 322), and `DashboardTest`
(23 tests) asserts against the shapes they return. Change the implementations, keep the return arrays
identical, and the dashboard and its 23 tests need **no edit at all**. That is the entire reason
report work is stage 7 and not stage 2.

### 6.1 `Modules/Accounting/app/Services/InvoiceReader.php`

- **`totals()`** (lines 94–141). Today: loads every outstanding invoice with its payments and does
  total-minus-applied per invoice in PHP. Its own docblock (lines 76–93) admits this reads every
  outstanding row on every call and says "the book itself would need a materialised running total (a
  ledger-style row updated by `PaymentRecorder`, not recomputed here) if it ever grew that large — no
  such row exists today." **The journal is that row.** New: fetch `journal_lines` where
  `account_code = '1100'`, group by currency, add debits and subtract credits through `Money`. One
  query, no per-invoice payment load. Return shape unchanged.
- **`agedReceivables()`** (lines 259–316). Bucketing needs `due_on`, so it still joins invoices. New:
  fetch `1100` lines with their entry's `reference_id`, group by invoice, bucket by the invoice's
  `due_on`. The bucket boundaries (lines 385–398) are already mutation-tested and **must not move** —
  the previous wave's mutation "shifted a boundary one day" produced "30 days overdue landed in
  31–60".
- **`revenueByMonth()`** (lines 144–186). New: `4000` credits grouped by month of `occurred_at`.
  ⚠️ The natural journal answer is one series **per reporting currency**; the contract's answer is a
  single lira series by construction (its docblock, lines 115–156, defends that at length). Keep the
  contract. The implementation reads the frozen lira figure off the line — which is the same figure it
  reads off the document today — and keeps `counted`/`excluded`. **This method gets no better from the
  journal.** Do it last, or not at all.
- **`revenueByClient()`** (lines 189–256). Same, joining `journal_entries.reference_id` → invoice →
  customer. Do **not** denormalise `customer_id` onto `journal_entries`: the reference is already
  there and a second copy is a second thing that can be wrong.

### 6.2 `Modules/Accounting/app/Services/ExpenseReader.php`

- **`expensesByMonth()`** (lines 59–112). New: `5000` debits grouped by month.
  🔴 **This will still return zero on the owner's real data** (measured: counted=0, excluded=8),
  because the exclusion is in `lira()` (lines 135–146) demanding `reporting_currency === TRY` and every
  expense froze USD. The journal carries the same frozen figures. Do not let anybody believe this work
  fixes the empty expense chart — see §9.6.

### 6.3 `resources/views/pages/⚡dashboard.blade.php`

**No change**, if §6's headline rule holds. Its docblock at lines 25 and 81–83 already states that
every figure arrives already summed from inside Accounting, which is exactly the property that makes
the swap invisible here.

### 6.4 `Modules/Accounting/resources/views/components/⚡reports.blade.php` (2,495 lines)

The largest single file in the plan and the last one to touch. What changes:

- **`reported()`** (lines 294–317) — groups frozen reporting figures per currency across document
  rows. From the journal it becomes the same grouping over `journal_lines.reporting_currency` /
  `reporting_amount`. The 🔴 comment at lines 276–290 about a mixed book being normal stays true word
  for word and must be carried across.
- **`ageing()`** (531–578) and **`agedReceivables()`** (955–1017) → read `1100` per currency, as §6.1.
- **`byCurrency()`** (600–632) → invoiced from `4000` + `2100`, received from `1000` debits,
  outstanding from `1100`.
- **`topClients()`** (821–868) → `4000` credits joined through the reference.
- **`trend()`** (725–809) → `4000` credits and `5000` debits by month, per currency. This is the
  method that gets simplest.
- **`realisedFx()`** (642–657) → **unchanged.** It reads `payments.fx_gain_loss`, which posts no
  journal line (§4b). Leave it alone.
- **`unrealised()`** (668–708) → **unchanged.** It calls `PaymentRecorder::unrealised()`, which writes
  nothing and is a report by design (`DECISIONS.md`: "a test asserts the ledger is unchanged by it").
  That test must keep passing against the journal too.
- **`taxSummary()`** (1242+) → `2100` gives KDV directly, but ⚠️ only if the issue-vs-collection
  question in §4(a) is answered. Until it is, leave `taxSummary()` reading the documents. The 🔴 rule
  in its docblock — "KDV is totalled from what each invoice froze, never by re-applying a rate to a
  subtotal" — is satisfied either way, since the journal line *is* what the invoice froze.

### 6.5 The one report that genuinely gets better

**`cashFigures()`** (lines 1082–1128) and the P&L built on it. Today its docblock names its own
approximation out loud (lines 1064–1075):

> no lira rate is frozen on the payment row itself, so a collection that arrived months after issue is
> valued here at the issue-date rate rather than the collection-date one.

The journal removes that, because a payment's `1000 Cash` line freezes its reporting figure at
`paid_at`'s rate (`PaymentRecorder::inReportingCurrency()` already computes exactly this at lines
315–330 — the figure exists today and is written to `ledger_entries.reporting_amount`, then read by
nothing).

🔴 **This changes the printed P&L numbers.** It is a correction, not a regression, but the reports page
currently *states* the approximation and would have to stop. Expect several assertions in
`tests/Feature/AccountingReportsTest.php` (22 tests) to move, and expect the handover to have to say
plainly that the P&L figures changed and why. This is also open debt item 3 on the accounting handover
("`payments` has no frozen reporting figure … Needs one column written at the moment of payment") —
the journal line *is* that column, and it arrives without altering the `payments` table.

---

## 7. The invariants worth a test, and the mutation that proves each

Every one of these must be broken deliberately, the failure confirmed, and the code restored. A test
that passes both ways is worth nothing — the last three sessions' highest-value habit.

### 7.1 The journal balances to zero per currency, per entry

Walk every `JournalEntry`, group its lines by `currency`, sum debits and credits through `Money`,
assert equal. **Per currency, per entry — not across the whole book, and not on the reporting
columns.**

⚠️ **The reporting columns are memo and are deliberately not required to balance.** Each line freezes
its own reporting figure at its own rate; a line whose rate was missing freezes null. Requiring the
reporting column to balance would mean posting a plug that is sometimes un-computable, and the
module's rule is that a missing rate produces nulls, never a refusal. State this in the test's own
docblock so nobody "fixes" it later. This is a real weakening of the classical double-entry guarantee
and §9.3 defends it.

**Mutation:** in `JournalPoster::invoiceIssued()`, debit `1100` with `$invoice->subtotal` instead of
`$invoice->total`.
**Expected failure:** `Journal entry #1 for INV-0038 does not balance in USD: debits $1,000.00,
credits $1,200.00. Every entry balances per currency or it is not a journal entry.`

### 7.2 A reversal restores the balance

Record a payment; assert `1000` standing balance in USD is `'1500.000000'`. Reverse it; assert
`'0.000000'`.

**Mutation:** delete the `$entry->reverse(...)` call from `PaymentRecorder::reverse()`, leaving the
soft-delete and the status recompute.
**Expected failure:** `Failed asserting that two strings are identical. Expected '0.000000', actual
'1500.000000'.` — the same shape the previous wave's equivalent mutation produced against
`ledger_entries`.

### 7.3 A backfilled document reports in the currency it froze

Take an invoice that froze `USD`; set `config(['accounting.reporting_currency' => 'TRY'])`; run the
backfill; assert every line of its entry has `reporting_currency === 'USD'`.

**Mutation:** in the backfill command, replace `$invoice->reporting_currency` with
`InvoiceIssuer::reportingCurrency()`.
**Expected failure:** `The backfill moved INV-0038 to the currency the setting names today. Nothing
may backfill history. Failed asserting that 'TRY' matches expected 'USD'.`

This is the direct descendant of the previous wave's mutation-tested invariant "Issuing does not
backfill history", which failed with "Nothing may backfill history". Reuse the phrasing so the two
read as the same rule.

### 7.4 A cross-currency settlement posts two entries in one group

Record a USDT payment against a USD invoice. Assert two `JournalEntry` rows share a `group_uuid`,
that each balances in its own currency (7.1 covers this), and that `1900` carries a standing balance in
both currencies.

**Mutation:** post one entry containing both currencies' lines.
**Expected failure:** the 7.1 assertion fires — `does not balance in USDT: debits ₮1,000.00,
credits ₮0.00`.

### 7.5 No monetary column is a float, and none is summed in SQL

Extend `NoFloatsInMoneyTest` as §2.7 describes.

**Mutation:** add `'debit' => 'float'` to `JournalLine::casts()`.
**Expected failure:** `Modules\Accounting\Models\JournalLine::$debit is cast as "float". It must be
decimal:N, which returns a string.`

### 7.6 The old ledger is closed

Assert `LedgerEntry::query()->create([...])` throws `LogicException`.

**Mutation:** remove the `creating` guard from `LedgerEntry::booted()`.
**Expected failure:** `Failed asserting that exception of type "LogicException" is thrown.`

### 7.7 Void refuses when payments stand

Issue an invoice, record a payment, attempt to void. Assert the invoice is still not void and the
toast names the payment.

**Mutation:** `if (false)` on the guard in `voidInvoice()`.
**Expected failure:** `A voided invoice left $1,200.00 of cash standing against no receivable.`

### 7.8 The chart in the table equals the chart in code

Assert `ledger_accounts` codes are exactly `Accounts::all()` keys, with matching `type` and
`normal_balance`.

**Mutation:** add one account to `Accounts::all()` without reseeding.
**Expected failure:** `The chart of accounts in ledger_accounts does not match
Modules\Accounting\Support\Accounts: missing 5900. Reseed, or the FK will reject a line nobody can
explain.`

### 7.9 Every issued invoice has exactly one standing issue entry

Seed, then walk `Invoice::issued()` and assert one `kind = invoice_issued` standing entry each. This
is the test that catches a *new* writer added later that forgets to call the poster — the known cost of
choosing explicit calls over an observer (§4).

**Mutation:** remove the poster call from `InvoiceIssuer::issue()`.
**Expected failure:** `INV-0041 was issued and posted nothing to the journal. Every issue goes through
JournalPoster; a new caller that skips it leaves a receivable nobody recorded.`

⚠️ Scope this to invoices issued **through `InvoiceIssuer`**. `InvoiceFactory` writes `sent_at`
directly and bypasses the service; asserting over raw factory output would fail everywhere for a
reason that is not a defect.

### 7.10 An account with history cannot be deleted

Assert deleting a `ledger_accounts` row that has lines throws a constraint violation. `PRAGMA
foreign_keys` returns **1** on this install, measured, so the FK is live.

**Mutation:** change `restrictOnDelete()` to `nullOnDelete()` in the migration.
**Expected failure:** the delete succeeds and the assertion `expectException` fails — plus, more
usefully, a second assertion that no line has a null `account_code`.

---

## 8. The order of execution

Each stage leaves the suite green. Sizes are honest estimates in files touched and tests affected,
against the 1,305-test / 6,284-assertion baseline. **Scaling this down is the owner's call** — the
natural stopping points are after stage 4 (the book is complete and correct, nothing reads it) and
after stage 7 (the readers use it, the reports page does not).

| # | What | Files | Tests | Shippable? |
|---|---|---|---|---|
| 0 | `Accounts` support class, `ledger_accounts` migration, seeder, parity test | 4 new, 1 edited | +3 new, 0 edited | Nothing visible. The chart exists. |
| 1 | `journal_entries` + `journal_lines` migrations, `JournalEntry` / `JournalLine` models with append-only guards and `reverse()`, factories, morph aliases, `NoFloatsInMoneyTest` extension | 5 new, 2 edited | +8 new, 1 file edited | Nothing visible. The tables exist and refuse to be misused. |
| 2 | `JournalPoster`, posting on **payment only**, dual-writing beside `ledger_entries`; `payments.journal_entry_group_id` | 1 new, 2 edited, 1 migration | +6 new, ~2 edited (`InvoicingTest`) | Yes. New payments fill the journal; nothing reads it; every report unchanged. |
| 3 | `accounting:backfill-journal` with `--dry-run` and the reconciliation | 1 new, 1 test | +5 new | Yes. The owner runs it, reads the reconciliation, and the payment history is complete. |
| 4 | 🔴 Post on invoice issue and on expense. AR appears; the expense hole closes. Backfill extended. | 4 edited | ~+6 new, **~10 edited** across `AccountingModelTest`, `RecurringExpensesTest`, `InvoicingTest` | Yes, and this is the point at which the book is actually a book. |
| 5 | Void guard and void posting | 2 edited | +2 new, ~3 edited (`InvoicePagesTest`) | Yes. Behaviour change — must be written into the handover. |
| 6 | Stop writing `ledger_entries`; add the `creating` guard; point `⚡expense-edit::delete()` at the journal; seeder and factory | 5 edited | +1 new, ~8 edited | Yes. One ledger, one source. |
| 7 | Move `InvoiceReader` and `ExpenseReader` implementations onto the journal, **contracts unchanged** | 2 edited | 0 new, **0–4 edited** if the shapes hold | Yes. `DashboardTest` (23) should not need a line. |
| 8 | `⚡reports.blade.php` — cash-basis P&L off the journal, `cashFigures()` approximation removed | 1 edited (2,495 lines) | ~8 edited (`AccountingReportsTest`) | Optional. **The printed P&L numbers move.** |

Totals: ~22 files touched, ~31 tests added, ~35 assertions edited. The full suite is ~12 minutes and
exceeds a 600 s tool timeout — run it in the background, and only from the main thread.

Two rules for whoever executes this:

- 🔴 **Never `migrate:fresh`.** The dev database at `database/database.sqlite` holds the owner's real
  book — 7 invoices, 3 payments, 8 expenses, and no backup mentioned anywhere in the handovers.
  `migrate --force` is fine. Take a copy before running the backfill for the first time even in
  `--dry-run`.
- Anything that clicks or writes goes to **port 8124** (`storage/app/audit-copy.sqlite`). Port 8123 is
  the real book and is for reading.

---

## 9. What I would not do, and why

### 9.1 No chart-of-accounts editor, no journal-entry screen, ever

The research agrees (§1, §9) and every freelancer-tier product in its survey hides this. Adding it
would also break the one thing this design gets right: if the only way a line enters the journal is
through `JournalPoster`, then §7.1's balance invariant is a property of eight code paths rather than of
whatever a person typed into a form.

### 9.2 🔴 No automatic FX gain/loss journal posting — this contradicts the research

`ACCOUNTING-RESEARCH.md` §6 says the ledger "must post the difference to a dedicated FX Gain/Loss
account automatically" and calls it "very hard to bolt onto a single-entry model correctly". It is
right about the general case and wrong for this codebase.

`payments.fx_gain_loss` already holds the realised difference, in the invoice's currency, computed
from two *stated* rates (`PaymentRecorder::realisedFxGainLoss()`, lines 291–313), and it returns
`'0.000000'` rather than inventing one when the issue-date rate is missing. To post it as a journal
line you need a counterparty, and the counterparty is the *expected* settlement amount — which was
never booked, because the invoice was booked at its face total, not at a predicted collection. Posting
the gain against `1900 Currency exchange` would make `1900` net to zero in one currency and therefore
*look* reconciled, which is worse than an honest standing balance in two currencies.

So: `1900` records the conversion as two matched entries with a shared `group_uuid`, `fx_gain_loss`
stays a memo on the payment row, and `⚡reports::realisedFx()` keeps reading it. If a later session
wants a posted FX account, the prerequisite is booking an expected-settlement figure at issue, and
that is a much larger change than it sounds.

### 9.3 No requirement that the reporting columns balance

A classical multi-currency ledger balances in a base currency, always. This one does not, and cannot,
because rule 3 says a converted figure is frozen per row and never re-derived, and
`InvoiceIssuer::reportingFigures()` writes nulls when a rate is unavailable rather than blocking. A
book where six invoices froze USD and every future one freezes TRY has no single base currency to
balance in — that is the measured state of this install (§0.2), not a hypothetical.

The honest design is: **balance in transaction currency, treat reporting as memo, and say so in the
invariant test's docblock.** An accountant reading this should know it up front rather than discover it.

### 9.4 No cash/bank account per currency

§1.2. The research recommends it; the currency column already carries that information and duplicating
it creates two things that can disagree.

### 9.5 No account per expense category

§1.1. The category is free text; an account per category is a user-editable chart of accounts wearing
a disguise.

### 9.6 🔴 No re-freezing of history to fix the empty lira reports

`expensesByMonth()` returns 0 counted / 8 excluded on real data, and `revenueByMonth()` 2 of 6. It is
tempting to have the backfill "fix" this by converting the USD-frozen rows to TRY at the rates in force
on their original dates. **Do not.** Rule 3 exists because a converted figure frozen on a document is
the record of what was believed then; re-deriving it — even at a historically correct rate — replaces
evidence with a recomputation, and there is no audit trail of the substitution.

If the owner wants lira figures for that history, the correct shape is a *separate*, explicit,
one-time, logged re-statement with its own document trail and its own reversal path — a feature, not a
side effect of a migration. It is not in this plan.

### 9.7 No trial balance or balance sheet page

The data supports both after stage 4 and neither is hard. But the research ranks the balance sheet
nice-to-have for a solo freelancer ("rarely needs it for anything but a loan application"), and a page
is the one part of this work that has never been rendered in a browser. Build the engine, ship it
invisible, then decide.

### 9.8 No model observers

§4. They would fire in factories and seeders, and they cannot hold a stated rate.

### 9.9 No changes to the reader contracts

§6. They are the seam that keeps `DashboardTest`'s 23 tests from moving.

---

## 10. Everything unverified, collected

Nothing below prints a number, on purpose. Silence beats a wrong tax number — the standing rule of
this codebase.

- **Whether KDV for a serbest meslek erbabı is assessed on issue or on collection.** Decides whether
  `2100 KDV payable` should post at (a) or at (b), and whether a KDV report over that account must be
  restricted to collected invoices. §4(a).
- **Whether Art. 94 stopaj reaches a foreign payer.** Already recorded as unverified in
  `config.php` lines 164–177. Decides whether a withheld-tax account is needed at all. §1.1.
- **Turkish treatment of a USDT receipt** — foreign-currency receipt, or crypto-asset disposal with its
  own rules. Already flagged as the highest-risk open question in the research. Decides whether the
  `1900` two-entry treatment in §4(b) is the right *tax* shape as well as the right *bookkeeping*
  shape.
- **Whether realised FX gain/loss is taxable income or a deductible expense in Turkey.** Decides
  whether §9.2's memo treatment is adequate for filing.
- **Whether the owner ever has unpaid supplier bills.** Decides whether `2000 Accounts payable` and a
  bill concept are needed, and whether §4(f)'s "credit Cash" assumption holds.
- **The owner's actual opening cash balance, per currency.** Not in the schema. §5.2.
- **Whether the owner wants an accrual view at all**, or only cash. The journal supports both after
  stage 4; the question is which one the reports page should lead with, and that is the owner's, not
  mine.
