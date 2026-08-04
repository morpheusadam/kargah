# Freelancer Accounting System — Feature Research & Prioritised Specification

Scope: Kargah is a self-hosted, single-user (one freelancer) workspace built on Laravel/SQLite.
This document specifies what its `Accounting` module should become, based on what established
tools (FreshBooks, Wave, Zoho Invoice/Books, QuickBooks Self-Employed, Xero, Harvest, Bonsai,
Invoice Ninja, Akaunting, Firefly III) actually ship, and what freelancers actually report using.

**Owner context (verified from the codebase, not assumed):** the freelancer is based in **Turkey**.
`Currencies::supported()` returns exactly **USD, TRY (Turkish Lira), USDT (Tether)**. The
**reporting currency is TRY** — invoices carry `issue_rate_to_try`, `try_equivalent`,
`issue_rate_source`, `rate_note`. The rate source is **TCMB EVDS** (the Turkish central bank's
statistics service), alongside Frankfurter for reference FX rates and CoinGecko for the USDT leg.
A code comment notes that an invoice to a domestic Turkish company must show its legally required
lira equivalent, and that Kargah refuses to substitute a market rate when the TCMB rate is
unavailable. This shapes section 4 (tax) and section 6 (multi-currency/FX) below, which are
Turkey-specific.

Each section states: what it is, why (or why not) a freelancer needs it, what the established
tools do (cited), and a priority. Priorities: **must-have** (build first, the product is
incomplete without it) / **should-have** (build soon after, clearly expected) / **nice-to-have**
(defer, low frequency of real use).

---

## 1. The core ledger: single-entry vs double-entry, and whether a chart of accounts earns its keep

**The honest trade-off, because this is the expensive-to-reverse decision:**

Single-entry (record each transaction once, as income or expense) is what most freelancer-facing
tools present to the user — it is fast to enter, requires no accounting literacy, and matches how
a freelancer actually thinks ("I got paid $500", "I spent $40 on software"). Double-entry (every
transaction hits at least two accounts, debit = credit) is what accountants and auditors need, and
it is the only model that can produce a real balance sheet, catch data-entry errors by construction
(the books must balance), and support proper multi-currency FX gain/loss and accrual-basis
reporting later.

The pattern across nearly every mainstream tool is: double-entry under the hood, single-entry-style
data entry on the surface. Wave "automatically generates two entries for every financial
transaction" while the user just fills in an invoice or expense form — [waveapps.com](https://www.waveapps.com/accounting/freelancers), [business.org](https://www.business.org/finance/accounting/wave-accounting-review/). Akaunting is explicitly built as full double-entry — general ledger, manual journals, trial
balance, chart of accounts, balance sheet — but the freelancer-facing screens are still just
"create an invoice" / "record a bill" — [akaunting.com/apps/double-entry](https://akaunting.com/apps/double-entry), [akaunting.com chart of accounts docs](https://akaunting.com/hc/docs/double-entry-accounting/creating-a-chart-of-accounts/). QuickBooks
Solopreneur (Intuit's cheapest, freelancer-targeted tier) deliberately has no user-facing chart
of accounts at all — it maps everything to fixed Schedule-C categories instead, because "you do
not need to love chart-of-accounts configuration to get real value from accounting software" —
[fondo.com](https://fondo.com/blog/quickbooks-solopreneur-vs-simple-start), [jamietrull.com](https://jamietrull.com/2025/02/05/quickbooks-solopreneur/). Firefly III, the closest
self-hosted comparator that is purely a ledger, is full double-entry by design and treats it as
the whole point of the product — [firefly-iii.org](https://www.firefly-iii.org/), [github.com/firefly-iii](https://github.com/firefly-iii/firefly-iii) — but note Firefly III has no
invoicing, no client/vendor concept, and no tax reporting; it is a personal-finance ledger, not a
freelance business tool, which is why it is a reference for the ledger engine only, not the whole
module.

My judgement (not sourced, this is the call to make): build double-entry as the storage model
now, because retrofitting it under years of single-entry invoice/expense data later is a real
migration project, and Kargah will eventually need a correct balance sheet, correct FX gain/loss,
and correct accrual reports if the practice grows or takes on a bookkeeper/accountant. But do not
expose a chart-of-accounts editor or journal-entry screen to the user. Ship a small, fixed,
opinionated set of accounts (Cash/Bank per currency, Accounts Receivable, Accounts Payable, a
handful of Income categories, a handful of Expense categories, Owner's Equity/Drawings) that the
invoice, expense, and payment screens post against automatically. This is exactly the Wave/Akaunting/
QuickBooks-Solopreneur pattern: double-entry engine, single-entry UX. A freely editable chart of
accounts for a one-person shop is ceremony borrowed from multi-entity corporate accounting — every
source above treats it as something the small/solo tier hides, not something it leads with.

**Priority: must-have** (the ledger model itself — decide once, get it right). The chart-of-accounts
editor as a user-facing feature: **nice-to-have**, possibly never.

---

## 2. Income side

### 2.1 Invoices — statuses, numbering, recurring, deposits, partial payments, credit notes

- **Statuses.** Draft, Sent, Viewed, Partially Paid, Paid, Overdue, Void, is the de-facto set
  across the category; Kargah's existing invoice basics likely already cover most of this.
- **Numbering.** Tax authorities in most jurisdictions (UK HMRC, EU VAT Directive, Australia's ATO,
  US IRS for deduction support) expect a "sequential, unique" identifier that is never reused, even
  for voided invoices — void and re-issue under a new number, don't reuse the old one —
  [tofu.com/blog/invoice-numbering](https://tofu.com/blog/invoice-numbering), [statrys.com](https://statrys.com/blog/what-is-an-invoice-number). A common recommended
  format is year-prefixed sequential (e.g. 2026-0001), which resets cleanly each year and scales —
  [tofu.com](https://tofu.com/blog/invoice-numbering). For Turkey specifically, the legally required document for a freelancer
  (serbest meslek erbabi) is the SMM / e-SMM, not a generic invoice — see section 4 — and that document
  has its own numbering/sequencing under GIB's e-belge regime, separate from any internal reference
  Kargah keeps for the client-facing document.
- **Recurring invoices.** Every tool in the survey has this: FreshBooks recurring templates
  auto-generate on a schedule (weekly/monthly/etc.) and can auto-charge via AutoPay/Advanced
  Payments — [support.freshbooks.com](https://support.freshbooks.com/hc/en-us/articles/222843308-How-do-I-create-a-recurring-template), [freshbooks.com/advanced-payments](https://www.freshbooks.com/advanced-payments); Invoice Ninja and Zoho Invoice both do the
  same — [invoiceninja.com/features](https://invoiceninja.com/features/), [zoho.com](https://www.zoho.com/blog/general/zoho-invoice-vs-zoho-books.html). This is core to retainer-based freelance work.
- **Deposits / retainers.** FreshBooks lets you request a fixed amount or a percentage of the
  invoice subtotal up front, before the balance is due — [freshbooks.com/advanced-payments](https://www.freshbooks.com/advanced-payments).
- **Partial payments / payment schedules.** FreshBooks supports letting a client pay an invoice in
  instalments on set dates — [freshbooks.com/advanced-payments](https://www.freshbooks.com/advanced-payments). This is very common for larger fixed-fee
  projects (e.g. a 30/30/40 split by milestone). Note: SMM in Turkey is issued on a collection
  basis, at the moment payment is received (see section 4) — a partial payment against a fixed-fee
  invoice likely means a partial SMM at each collection event, not one SMM at invoice time.
- **Credit notes.** FreshBooks issues credits for overpayments, prepayments, or goodwill, and
  consolidates them into a balance that can be applied to a future invoice —
  [support.freshbooks.com/credits](https://support.freshbooks.com/hc/en-us/articles/115011590827-How-do-credits-work). This matters for a real system because "just edit the old invoice"
  breaks the sequential-numbering / audit-trail requirement above — a credit note is the correct
  mechanism, not a mutation of a sent invoice.

**Priority: must-have** — statuses, sequential numbering, recurring invoices, partial payments.
**Should-have** — deposits/retainers, credit notes (needed as soon as the numbering rule above is
taken seriously, i.e. fairly early).

### 2.2 Estimates / quotes to invoice conversion

Every mainstream tool treats "quote/estimate" as a first-class document that converts 1:1 into an
invoice once accepted — this is explicit in Zoho ("both create... manage customer quotes and
estimates") — [zoho.com/blog](https://www.zoho.com/blog/general/zoho-invoice-vs-zoho-books.html) — and is the backbone of Bonsai's whole workflow: proposal accepted,
then auto-populates a contract, then logged hours auto-populate the invoice —
[medium.com/tales-of-a-solopreneur](https://medium.com/tales-of-a-solopreneur/hello-bonsai-a-swiss-army-knife-tool-for-the-freelancer-d753d2a7f5af), [mightyfreelancer.com](https://mightyfreelancer.com/hello-bonsai-freelancer-software-review/). Given Kargah already has
projects/boards, an estimate should be attachable to a project and, on acceptance, seed either a
single invoice or a recurring/milestone billing plan.

**Priority: should-have.**

### 2.3 Late fees and reminders

Bonsai sends automatic payment reminders on a schedule the freelancer sets —
[mightyfreelancer.com](https://mightyfreelancer.com/hello-bonsai-freelancer-software-review/); most tools in this category (FreshBooks, Zoho) support scheduled
reminder emails on overdue invoices as a matter of course, though I could not find a source that
breaks out an explicit percentage-based late fee feature separately from reminders in any of the
surveyed tools — treat late-fee automation as a config-only nice-to-have (a percentage or flat
amount added N days after due date) rather than a heavily-used feature; reminders are the thing
freelancers actually rely on.

**Priority: should-have** (reminders) / **nice-to-have** (automatic late fee calculation).

---

## 3. Expense side

- **Categories.** Universal across every tool; this is the minimum viable expense feature.
- **Receipts/attachments.** QuickBooks Self-Employed: "snap and store your receipts" —
  [quickbooks.intuit.com](https://quickbooks.intuit.com/learn-support/en-us/help-article/taxation/quickbooks-self-employed-tracks-self-employment/L6TLro1gO_US_en_US); FreshBooks: "snap photos of paper receipts and log them on
  the go." Table stakes.
- **Billable vs non-billable.** FreshBooks: mark any expense billable and it appears automatically
  on the client's next invoice — this is the single most valuable expense feature for a freelancer
  who fronts costs (software licenses, stock photos, contractor sub-work, travel) and re-bills the
  client. Avaza and Zoho both support the same split and let you drill into billable-vs-non-billable
  expense reports — [navan.com/billable-expense](https://navan.com/resources/glossary/what-is-billable-expense), [avaza.com](https://www.avaza.com/freelance-time-tracking-and-invoicing/), [zoho.com/books/expenses](https://www.zoho.com/us/books/accounting-software/expenses/).
- **Mileage.** QuickBooks Self-Employed auto-logs GPS trips and lets the user swipe business/
  personal — [quickbooks.intuit.com/mileage](https://quickbooks.intuit.com/learn-support/en-us/help-article/self-employment-taxes/learn-standard-mileage-actual-expenses-deduction/L45vu531N_US_en_US). This is a US-tax-driven feature (IRS standard mileage rate deduction); this
  research found no Turkish equivalent (see "Could not verify"). For a desk-based freelancer
  (the likely Kargah persona), this is low-value regardless; keep it out or make it a trivially
  simple optional expense sub-category, not a GPS-tracking feature.
- **Recurring expenses.** Zoho Books auto-generates recurring expense entries from a profile —
  [zoho.com/books/expenses](https://www.zoho.com/us/books/accounting-software/expenses/); Invoice Ninja does the same with currency and frequency —
  [invoiceninja.github.io/recurring-expenses](https://invoiceninja.github.io/en/recurring-expenses/). Useful for subscriptions/tools the freelancer pays monthly.
- **Vendor/supplier tracking.** Zoho Books/Invoice Ninja both track vendors, link bills to them, and
  flag due dates — [zoho.com/books/expenses](https://www.zoho.com/us/books/accounting-software/expenses/), [invoiceninja.com/features](https://invoiceninja.com/features/). For a solo freelancer this is usually overkill as a
  full accounts-payable workflow (no purchase orders, no vendor portal needed), but a lightweight
  "who did I pay" tag on expenses is worth having for the expenses-by-vendor report.

**Priority: must-have** — categories, receipts/attachments, billable/non-billable split.
**Should-have** — recurring expenses, lightweight vendor tag.
**Nice-to-have** — mileage tracking, full vendor/AP workflow (bills, purchase orders, vendor portal).

---

## 4. Tax — Turkey (serbest meslek erbabi / freelancer regime)

This section replaces an earlier draft that incorrectly assumed an Iran-based owner. The owner is
Turkey-based (confirmed from the codebase: TRY reporting currency, TCMB EVDS as rate source, see
the intro). A Turkish freelancer operating as a sole self-employed professional falls under
"Serbest Meslek Erbabi" (self-employed professional) status in Income Tax Law No. 193, with four
distinct, sourced obligations below. Given the coordinator's explicit warning that a tax office may
read the resulting document, every figure here is cited to a specific source, and anything not
cleanly sourced is pushed to "Could not verify" rather than stated as fact.

### 4.1 KDV (VAT) and the export-of-services exemption

Turkey's standard KDV rate for professional/freelance services (consulting, engineering, etc.) is
20% — [isbasi.com](https://isbasi.com/blog/freelance-calisanlar-nasil-serbest-meslek-makbuzu-smm-keser) (sourced via direct fetch of that article's figures).

For a freelancer invoicing a client abroad (the case that matters for Kargah, given the
USD/USDT currency support), the relevant regime is the hizmet ihracati (export of services) KDV
exemption. Four cumulative conditions must all be met for the exemption to apply: (1) the service
provider legal/business center must be in Turkey and the service produced using Turkey-based
organization; (2) the recipient residence or business center must be abroad; (3) the service must
be benefited from abroad; (4) the fee must be documented with an e-Invoice issued to the foreign
customer at a VAT rate of zero, using exemption code 302 — [vergimerkezi.com.tr](https://vergimerkezi.com.tr/hizmet-ihracati-kdv-istisnasi-ve-iadesi-2026/), [muhasebenews.com](https://www.muhasebenews.com/en/what-are-the-features-of-service-export-in-turkey/). If
any one condition fails, the standard rate applies instead — [vergimerkezi.com.tr](https://vergimerkezi.com.tr/hizmet-ihracati-kdv-istisnasi-ve-iadesi-2026/). This is the single
most consequential rule for Kargah's invoice document: an invoice to a foreign client should default
to the zero-rate exemption-code-302 path, but the software should not silently assume it applies —
the four conditions are a judgment call the freelancer (or their accountant) must confirm per
invoice.

Separately, Turkey offers an 80% income-tax exemption for freelancers and self-employed individuals
who export specific technical services directly to clients abroad, under Article 10 of the
Corporate Tax Law and Article 94 of the Income Tax Law — [masen.com.tr](https://www.masen.com.tr/en/the-80-tax-exemption-on-service-exports-in-turkey/), [a-m.com.tr](https://a-m.com.tr/tax-exemption-for-turkeys-digital-exporters/). This is an
income-tax relief, distinct from the KDV exemption above, and its exact scope (which service types
qualify) was not fully detailed in the sourced material, flagged in "Could not verify."

### 4.2 Stopaj (withholding) on freelancer income

When a Turkish tax-liable organization (a company, association, foundation, commercial enterprise,
etc.) pays a freelancer, it must withhold income tax at source under Article 94 of the Income Tax
Law — a 20% stopaj is applied to the gross service amount, withheld and remitted by the payer,
not the freelancer — [isbasi.com](https://isbasi.com/blog/freelance-calisanlar-nasil-serbest-meslek-makbuzu-smm-keser), [vergimerkezi.com.tr](https://vergimerkezi.com.tr/12-soruda-serbest-meslek-kazanci-rehberi/). Note this withholding obligation is specifically
tied to payments from Turkish tax-liable payers — whether or how it applies to a foreign client
paying a Turkish freelancer directly (the case most relevant to Kargah's USD-invoicing scenario) was
not addressed by the sourced material and is flagged in "Could not verify." An SMM (see 4.3)
reflects this split: the withholding is paid by the client (when the client is a Turkish
withholding agent), and the KDV portion is paid by the freelancer — [isbasi.com](https://isbasi.com/blog/freelance-calisanlar-nasil-serbest-meslek-makbuzu-smm-keser).

---

### 4.3 Serbest Meslek Makbuzu (SMM) — the required document

The legally required document for a Turkish self-employed professional's income is the Serbest
Meslek Makbuzu (SMM), not a generic commercial invoice. Issuing this receipt for services
rendered is mandatory under Article 236 of Tax Procedure Law No. 213 (general finding from the
freelancer-tax literature surveyed). Two properties of the SMM matter directly for Kargah's invoice
document design:

- It is issued on a collection basis — at the moment payment is actually received, not at the
  moment the service/work is delivered or the commercial invoice is sent — [isbasi.com](https://isbasi.com/blog/freelance-calisanlar-nasil-serbest-meslek-makbuzu-smm-keser). This is a
  materially different trigger than "invoice sent," and interacts directly with the partial-payment
  and deposit features in section 2.1: each collection event should be able to generate its own SMM.
- As of 2025, freelancers issue only the electronic form, e-SMM, not paper — [isbasi.com](https://isbasi.com/blog/freelance-calisanlar-nasil-serbest-meslek-makbuzu-smm-keser). The
  e-SMM mandate itself dates back further: a GIB communique published 19 October 2019 required all
  freelancers active as of 1 February 2020 to transition to e-SMM by 1 June 2020, and anyone starting
  self-employment from 1 February 2020 onward must register within three months of their start month
  — [esasdenetim.com](https://esasdenetim.com/2020-sirkuler/serbest-meslek-erbaplarinca-01062020-tarihinden-itibaren-kullanilmasi-zorunlu-olan-elektronik-serbest-meslek-makbuzu-esmm-uygulamasi-basvuru-yol-haritasi-1269). Unlike the e-Fatura/e-Arsiv revenue thresholds in section 4.5, e-SMM
  appears to apply to essentially all active freelancers regardless of revenue — none of the sourced
  material described a revenue floor for e-SMM specifically.

I could not find a source that enumerates the complete, exact legally mandated field list for an
SMM (beyond withholding amount, KDV amount, and net collectible amount implied by the 20/20 split
above), flagged in "Could not verify," because printing a document with a wrong or incomplete
field set is exactly the kind of mistake that matters here.

---

### 4.4 Gecici vergi (provisional/advance tax)

Self-employed professionals file a Gecici Vergi Beyannamesi (provisional tax return) quarterly,
four times a year, covering three-month earning periods, due by the 17th of the second month
following each period — [vergimerkezi.com.tr](https://vergimerkezi.com.tr/12-soruda-serbest-meslek-kazanci-rehberi/) (frequency), [musavirlerkulubu.com.tr](https://musavirlerkulubu.com.tr/makale/freelancer-ve-serbest-calisanlar-icin-kapsamli-vergi-rehberi-2026) (freelancers file
quarterly provisional tax on estimated annual profit). The provisional tax rate applied is the
first bracket of the income tax tariff, sourced as 15% for the 2026 tax year, applied to
the quarter's cumulative earnings — [logo.com.tr](https://www.logo.com.tr/blog/blog-detay/gecici-vergi-nedir-ve-nasil-hesaplanir) (mechanism: first-bracket rate applied to
3-month cumulative earnings), figure corroborated by a second source citing "yuzde 15" as the
first income tax bracket rate for 2026, with the bracket threshold at TRY 190,000 —
[muhasebetr.com](https://www.muhasebetr.com/gelir-vergisi-dilimleri/). Freelancers must also maintain a Serbest Meslek Kazanc Defteri
(self-employment income ledger) — [musavirlerkulubu.com.tr](https://musavirlerkulubu.com.tr/makale/freelancer-ve-serbest-calisanlar-icin-kapsamli-vergi-rehberi-2026). An
annual income tax return is then filed in March, reconciling the year's provisional payments
against the final liability — [musavirlerkulubu.com.tr](https://musavirlerkulubu.com.tr/makale/freelancer-ve-serbest-calisanlar-icin-kapsamli-vergi-rehberi-2026).

For Kargah, the actionable pattern is: a running, always-current "provisional tax owed this
quarter" indicator, computed from year-to-date SMM/invoice income at whatever bracket rate is
configured, never hardcoded, because bracket thresholds and rates are revalued annually (the 2026
figures above were explicitly described as updated by an 18.95% revaluation rate).

### 4.5 e-Fatura, e-Arsiv, e-SMM and the ozel entegrator question

Turkey's mandatory e-document ("e-Belge") regime has three relevant document types:

- e-Fatura (e-invoice, B2B/B2G): mandatory once a business's annual revenue exceeds a threshold
  reported as TRY 3 million (for the 2022 fiscal year, per one source) — [dddinvoices.com](https://dddinvoices.com/learn/e-invoicing-turkey), also referenced as the
  general TRA-set annual threshold — [vatcalc.com](https://www.vatcalc.com/turkey/turkey-e-invoice-e-fatura-and-e-arsiv-update/). Sources disagree on the exact current-year figure and on
  e-Arsiv's threshold specifically (one source gave TRY 30,000 lowering to TRY 10,000; a differently
  sourced page gave TRY 5,000 for non-taxable recipients and TRY 2,000 for taxable ones) —
  [dddinvoices.com](https://dddinvoices.com/learn/e-invoicing-turkey), [vatcalc.com](https://www.vatcalc.com/turkey/turkey-e-invoice-e-fatura-and-e-arsiv-update/). Given this direct disagreement between sources, treat all e-Fatura/e-Arsiv
  threshold figures as unverified and volatile, flagged explicitly in "Could not verify," and any
  implementation must read the current threshold from GIB's own published figures at build time, not
  from this document.
- e-SMM: as established in section 4.3, mandatory for essentially all active freelancers since
  2020, independent of the e-Fatura/e-Arsiv revenue thresholds above.
- Ozel entegrator (special integrator): GIB maintains an official list of licensed special
  integrators for e-document transmission — [ebelge.gib.gov.tr](https://ebelge.gib.gov.tr/efaturaozelentegratorlerlistesi.html) (first-party GIB source, confirms
  the mechanism exists and is GIB-licensed). No source directly stated whether a self-hosted
  application like Kargah could transmit e-SMM/e-Fatura documents straight to GIB's own portal, or
  whether an ozel entegrator (a paid, licensed intermediary) is functionally required for any
  third-party software, flagged in "Could not verify." This is the single most important open
  question for the tax section, because it determines whether Kargah's invoice document can ever be
  the compliance-grade legal document, or whether it must always be paired with a separate
  GIB-portal or entegrator-issued e-SMM for the transaction to be legally valid.

**Priority: must-have** — KDV field on invoice lines (rate plus the zero-rate/exemption-code-302
path for qualifying foreign-client invoices), stopaj awareness (display the 20% withholding split
on documents where it applies), a document that can hold all SMM-required data even if actual
e-SMM transmission is out of scope for v1 (see section 9).
**Should-have** — a running gecici vergi (provisional tax) estimate using a configurable
first-bracket rate, a year-end summary reconciling provisional payments.
**Nice-to-have / explicitly out of scope for v1**: direct e-Fatura/e-Arsiv/e-SMM transmission to
GIB or an ozel entegrator — this is a compliance-grade integration that needs its own properly
sourced spec and almost certainly a paid third-party integrator relationship, not something to bolt
onto a general accounting-features build.

---

## 5. Time and profitability

Time tracking to billable hours to invoice is Harvest's entire product thesis: track time against a
project/client, mark billable vs non-billable, and generate an invoice pre-populated from logged
time with the project's billing rate — [getharvest.com/billable-hours-tracker](https://www.getharvest.com/time-tracking/billable-hours-tracker-for-project-managers), [getharvest.com/time-tracking-app-with-invoicing](https://www.getharvest.com/time-tracking/time-tracking-app-with-invoicing).
Harvest also tracks project profitability margins as work progresses and reports on budget
burn against the project budget — [getharvest.com/project-profitability](https://www.getharvest.com/time-tracking/time-tracking-for-project-profitability). Bonsai's workflow is the same
shape: logged hours auto-populate invoices — [mightyfreelancer.com](https://mightyfreelancer.com/hello-bonsai-freelancer-software-review/).

Given Kargah already has projects/boards, this is a natural extension: a timer or manual time entry
per task/project, an hourly rate per project (or per client, with per-project override), and
"unbilled time" as a queue that feeds directly into invoice creation, the single most requested
pattern across every time-tracking-adjacent tool in the survey.

Hourly vs fixed-fee both need to be first-class project billing modes: hourly bills against
logged time at a rate; fixed-fee bills against milestones/deposits (section 2.1) with time logged
only for the freelancer's own profitability visibility, not for billing.

Project profitability equals revenue recognised on a project minus (time cost at an internal rate
plus billable/non-billable expenses attributed to the project). This is a genuinely useful report,
not just vanity — it's what tells a freelancer whether a fixed-fee project was actually worth it.

**Priority: must-have** — time entries linked to project plus billable flag, hourly rate on
project/client, unbilled-time to invoice flow.
**Should-have** — project profitability report (revenue vs. time cost vs. expenses).
**Nice-to-have** — budget-burn alerts, timer UI polish (start/stop widget, idle detection).

---

## 6. Multi-currency and FX — the real case: USD invoice, USDT settlement, TRY reporting

This section is more important than a generic multi-currency feature, because it is the actual
case Kargah's code already models: a freelancer invoices in USD, sometimes gets paid in USDT
(a stablecoin, not a fiat currency), and must report and declare in TRY, using TCMB EVDS as the
official rate source (per the codebase, per the coordinator's correction).

### General FX mechanics (sourced, not Turkey-specific)

An invoice is issued and recorded at the exchange rate on the transaction date; when payment
arrives, often weeks later at a different rate, the realized difference between the invoice-date
rate and the payment-date rate is a foreign-exchange gain or loss. Example given in the sourced
material: invoice a client for 10,000 euros at 1.10 USD/EUR (11,000 dollars booked); by the time
payment lands 30 days later the rate has moved to 1.05, so the freelancer actually receives 10,500
dollars, a 500 dollar realized FX loss — [beancount.io](https://beancount.io/blog/2026/05/03/foreign-exchange-gain-loss-multi-currency-accounting-small-business-guide). Under GAAP/IFRS, transactions are booked at the
transaction-date rate, and realized vs. unrealized FX gains/losses are reported separately —
[beancount.io](https://beancount.io/blog/2026/05/03/foreign-exchange-gain-loss-multi-currency-accounting-small-business-guide), [netsuite.com](https://www.netsuite.com/portal/resource/articles/accounting/multi-currency-accounting.shtml). Tax treatment of FX gains/losses (taxable/deductible or not) varies by
jurisdiction; the general sources found give no single global rule and none addressed Turkey
specifically — [finally.com](https://finally.com/blog/accounting/foreign-currency-small-business-accounting/) — flagged in "Could not verify."

### The TCMB rate rule (Turkey-specific, sourced)

For converting a foreign-currency-denominated invoice to Turkish lira, the rate to use is the
TCMB (Central Bank of Turkey) Doviz Alis Kuru (foreign currency buying rate) as of the invoice
date — this is the rate considered in taxation matters, per Turkish accounting-practice sources —
[muhasebetr.com](https://www.muhasebetr.com/yazarlarimiz/mvefatoroslu/001/), and confirmed in a second source discussing the practical rule for converting invoice
amounts: "the exchange rate used to convert a foreign-currency invoice to Turkish lira should be
the TCMB Foreign Currency Buying Rate as of the invoice date." However, if the parties have agreed
between themselves to use the selling rate (satis kuru) instead, the KDV base should be calculated
using that agreed current rate — [gulbenkmusavirlik.com](https://www.gulbenkmusavirlik.com/doviz-cinsinden-mal-alim-islemlerinde-kullanilacak-kur-hk-477.html) (source discussing the general
buying-rate-as-default, agreed-rate-as-override pattern for foreign-currency transactions).
Note this directly matches the codebase's stated behavior: it uses TCMB EVDS as the rate source and
"refuses to substitute a market rate when the TCMB rate is unavailable" — i.e. the code is already
enforcing exactly this buying-rate-on-invoice-date rule rather than falling back to a convenience
rate, which is the legally correct default per the sourced material above.

### USDT as the settlement currency

None of the general or Turkey-specific sources in this research addressed USDT/stablecoin
settlement directly — this is a crypto-asset question, not a standard FX question, and Turkish tax
treatment of crypto-asset receipts by a freelancer (is USDT treated as foreign currency received,
or as a crypto-asset disposal event with its own rules?) was not found in this research. This is
flagged in "Could not verify" and is likely the highest-risk unresolved question in this entire
document, given that the codebase already has a CoinGecko-sourced rate for the USDT leg — the
product decision of "USDT in equals a foreign-currency receipt at the CoinGecko rate" versus "USDT
in is a crypto-asset transaction with separate reporting rules" needs a sourced answer, ideally from
a Turkish tax advisor, before the ledger's FX gain/loss logic is finalized for that leg.

### What this means for the ledger model (section 1)

The double-entry design needs, from day one, a currency field on every transaction, a stored
exchange rate at the time of the transaction, and TRY as the base/reporting currency that
everything rolls up into. When a USD invoice is settled (in USD or in USDT) and the settlement rate
differs from the invoice-date TCMB rate, the ledger must post the difference to a dedicated FX
Gain/Loss account automatically — this only works cleanly if double-entry was chosen in section 1;
it is very hard to bolt onto a single-entry model correctly.

**Priority: must-have** — per-invoice currency (USD/TRY/USDT), stored TCMB buying rate at invoice
date, stored rate at settlement date, automatic FX gain/loss posting, TRY as the fixed reporting
currency (this already matches the existing schema, per the coordinator).
**Should-have** — a manual override / rate-note field for cases where TCMB has no rate for a given
day or the parties agreed a different rate (the schema's existing `rate_note` field suggests this
is already anticipated).
**Nice-to-have** — historical rate charts, multi-currency balance-sheet views per currency.

---

## 7. Reports that matter (ranked)

Sourced convergence across the freelancer-bookkeeping literature: "the four essential reports to
start with are cash flow statement, profit and loss, accounts receivable aging, and accounts
payable aging" — [relayfi.com](https://relayfi.com/blog/9-financial-reports-every-owner-needs/). The A/R aging report specifically tracks who owes money and how
overdue — [highradius.com](https://www.highradius.com/resources/Blog/types-of-accounts-receivable-reports/). Freelancers are also specifically noted as wanting P&L by client,
cash-flow summaries, and revenue-by-platform/client breakdowns — [relayfi.com](https://relayfi.com/blog/9-financial-reports-every-owner-needs/).

Ranked for this build, combining the sourced material with the shape of a one-person practice:

1. Profit & Loss (income minus expenses, by period, reported in TRY), must-have, the single
   most-opened report.
2. Accounts Receivable Aging, must-have; a freelancer's biggest cash-flow risk is unpaid
   invoices, and this is the report that surfaces it.
3. Cash flow (money actually in the bank, forward-looking), must-have; sourced advice is to
   check this weekly, not monthly, when cash is tight — [relayfi.com](https://relayfi.com/blog/9-financial-reports-every-owner-needs/).
4. Revenue by client, should-have; directly answers "who is my business actually resting on."
5. Expenses by category, should-have; this is the tax-prep report from section 4.
6. Tax summary (KDV collected/exempt, stopaj withheld, gecici vergi accrued), should-have,
   becomes must-have once the e-Fatura/e-SMM integration (section 4.5) exists.
7. Project profitability (section 5), should-have for anyone doing fixed-fee work.
8. FX gain/loss report (section 6), should-have; specific to Kargah's multi-currency reality,
   not generic to every tool surveyed, but directly relevant given the USD/TRY/USDT setup.
9. Accounts Payable aging, nice-to-have for a solo freelancer with few recurring vendor bills.
10. Balance sheet, nice-to-have; useful once double-entry (section 1) is solid, but a solo
    freelancer rarely needs it for anything but a loan application.

---

## 8. Dashboard

Sourced design guidance: a dashboard should be scannable in 30-60 seconds, with primary outcome
KPIs top-left, leading indicators top-right, trend charts in the middle, and diagnostic detail at
the bottom — [asrify.com](https://asrify.com/blog/performance-dashboards-decisions). A concrete freelancer-dashboard example proposes exactly
"revenue, utilization, focus time, and pipeline" as the top-level set —
[asrify.com](https://asrify.com/blog/performance-dashboards-decisions); a KPI-dashboard reference source lists monthly revenue, billable
utilization, effective hourly rate, and growth as the core freelancer metrics —
[simplekpi.com](https://www.simplekpi.com/KPI-Dashboard-Examples/Freelance-And-Gig-Economy-KPI-Dashboard).

Concrete recommendation for Kargah's accounting dashboard, all figures in TRY (the reporting
currency), with a currency toggle where relevant:

- Top-left, single big number: Outstanding receivables total (sum of unpaid plus
  partially-paid invoices, converted to TRY at current rates), with a red/amber/green split by how
  overdue.
- Top-right, single big number: This month's revenue (recognised/invoiced, TRY-equivalent) vs.
  last month, as a simple delta.
- Trend chart, middle, full-width, line chart: Revenue vs. Expenses per month, trailing 12
  months, in TRY. This is the one chart that answers "is the practice actually profitable" at a
  glance.
- Second chart, bar chart: Cash in vs. cash out per month (distinct from the revenue/expense
  line above, because invoiced revenue and cash received are not the same thing, exactly the
  FX-timing and partial-payment gap from sections 2 and 6).
- Third chart, horizontal bar or donut: Revenue by client, trailing 12 months, top 5-8 clients
  plus "other." Answers concentration risk.
- Fourth chart, donut or stacked bar: Expenses by category, current month or trailing 12
  months.
- List/table widget: Aged receivables, the 5-10 oldest unpaid invoices, client name, amount,
  currency, days overdue, with a one-click "send reminder."
- List/table widget: Upcoming recurring invoices/expenses due in the next 7-14 days.
- Small stat: cumulative FX gain/loss for the year-to-date, given the USD/USDT exposure.
- If time tracking (section 5) ships: a small bar chart of billable vs non-billable hours this
  week/month, and effective hourly rate (revenue divided by hours logged) as a single number, the
  metric the KPI source calls out specifically — [simplekpi.com](https://www.simplekpi.com/KPI-Dashboard-Examples/Freelance-And-Gig-Economy-KPI-Dashboard).

**Priority: must-have** — outstanding receivables, revenue-vs-expense trend line, aged-receivables
list. **Should-have** — cash-in/cash-out bar chart, revenue-by-client, expenses-by-category,
FX gain/loss stat. **Nice-to-have** — effective hourly rate, billable-utilization chart,
upcoming-recurring widget.

---

## 9. What to deliberately NOT build

- Payroll. Every source treats this as an add-on even for small teams (Xero routes it through a
  third party like Gusto) — [ecloud-experts.com](https://ecloud-experts.com/xero-for-small-businesses-is-it-the-right-fit-for-your-business/). Kargah is explicitly single-user; there are no employees to
  pay. Out of scope, full stop, unless the product's premise changes.
- Inventory management. A service-based freelancer sells time and deliverables, not stock.
  Xero's own guidance notes multi-user access, inventory management, and payroll are the things a
  solo operator typically does not need from small-business accounting software —
  [xero.com/sole-proprietor-guide](https://www.xero.com/us/guides/sole-proprietor-accounting-software/), [ecloud-experts.com](https://ecloud-experts.com/xero-for-small-businesses-is-it-the-right-fit-for-your-business/). Out of scope.
- Bank feeds / live bank-account sync (Plaid-style). Genuinely useful in the abstract, but it
  requires third-party financial-data aggregation, and it conflicts with "self-hosted, one user,
  full data control" as a design premise. No source in this research addressed Plaid or equivalent
  aggregator coverage for Turkish banks specifically, flagged in "Could not verify" if this is
  reconsidered later. Recommend manual/CSV bank reconciliation instead of live feeds, out of scope
  for v1, and possibly permanently.
- Full accounts-payable workflow (purchase orders, vendor portal, multi-step bill approval).
  Zoho/Invoice Ninja both offer this — [zoho.com/books/expenses](https://www.zoho.com/us/books/accounting-software/expenses/), [invoiceninja.com/features](https://invoiceninja.com/features/) — but it is built for
  businesses with a purchasing department, not a single freelancer who occasionally pays a
  contractor or a software subscription. A lightweight vendor tag on expenses (section 3) covers
  the real need.
- Multi-user / roles / approval chains. No purpose in a single-user product.
- A user-editable chart of accounts / journal-entry UI (see section 1). The double-entry engine
  should exist; exposing raw journal entries to a non-accountant user invites broken books, and none
  of the freelancer-tier products in this survey lead with it.
- GPS-based mileage tracking (section 3), a US tax-deduction mechanic with no sourced Turkish
  equivalent found in this research, and it does not fit a desk-based freelance practice regardless.
- Direct e-Fatura/e-Arsiv/e-SMM transmission to GIB (section 4.5), until the ozel-entegrator
  question is resolved with a proper source; building against an unverified compliance requirement
  risks shipping something that looks compliant but isn't.

---

## The ten things to build first, in order

1. Double-entry ledger core (hidden from the user) with a fixed, small chart of accounts:
   per-currency cash/bank (USD, TRY, USDT), Accounts Receivable, Accounts Payable, Income
   categories, Expense categories, Owner's Equity. Every feature below posts against this.
   (section 1)
2. Multi-currency support baked into the ledger from day one: currency plus TCMB-sourced buying
   rate stored per transaction at the invoice date, TRY as the fixed base/reporting currency,
   automatic FX gain/loss posting on settlement (including the USDT leg via the existing
   CoinGecko-sourced rate). (section 6) This must come this early because retrofitting it after
   invoices/expenses exist in a single-currency shape is expensive, and the schema already has the
   fields for it.
3. Invoices, hardened: enforce sequential non-reusable numbering, add partial payments and
   deposits/retainers, add credit notes as the correction mechanism (never edit a sent invoice), and
   surface the KDV zero-rate/exemption-code-302 path for qualifying foreign-client invoices.
   (sections 2.1, 4.1)
4. Recurring invoices and recurring expenses. (sections 2.1, 3)
5. Expenses with billable/non-billable flag that feeds straight into the client's next invoice,
   plus receipt attachments and tax-category tagging. (sections 3, 4)
6. Time tracking tied to projects, with billable flag and hourly rate per project/client, and an
   "unbilled time to invoice" flow. (section 5)
7. Core reports: P&L, Accounts Receivable Aging, Cash Flow, Expenses by Category, Revenue by
   Client, FX Gain/Loss. (section 7)
8. Dashboard v1: outstanding receivables total, revenue-vs-expense trend line (12mo), aged
   receivables list, revenue-by-client chart. (section 8)
9. A Turkish-compliant document layer: an SMM-shaped data model (collection-basis trigger, KDV
   plus stopaj split, all fields the eventual e-SMM will need) even before any actual e-SMM/GIB
   transmission is built, and a configurable, never-hardcoded gecici vergi (provisional tax)
   estimate. (sections 4.3, 4.4)
10. Estimates/quotes that convert to invoices, and project profitability reporting (revenue vs.
    time cost vs. attributed expenses). (sections 2.2, 5)

Deliberately last/deferred beyond this list: automatic late fees, mileage tracking, full
accounts-payable/vendor workflow, direct e-Fatura/e-Arsiv/e-SMM transmission to GIB or an ozel
entegrator, any bank-feed integration, payroll, inventory, chart-of-accounts editor, and any
crypto-specific USDT tax logic beyond treating it as a settlement currency at the CoinGecko rate
(pending the sourced answer flagged below). (section 9)

---

## Could not verify

- The complete, exact legally mandated field list for a Serbest Meslek Makbuzu (SMM), beyond
  withholding amount, KDV amount, and net collectible amount implied by the 20%/20% split
  ([isbasi.com](https://isbasi.com/blog/freelance-calisanlar-nasil-serbest-meslek-makbuzu-smm-keser), [vergimerkezi.com.tr](https://vergimerkezi.com.tr/12-soruda-serbest-meslek-kazanci-rehberi/)).
- Whether a self-hosted application like Kargah can transmit e-SMM/e-Fatura documents directly to
  GIB's own portal, or whether a paid, licensed ozel entegrator (special integrator) is functionally
  required for any third-party software to produce a legally valid e-document. GIB's own list of
  licensed integrators confirms the mechanism exists ([ebelge.gib.gov.tr](https://ebelge.gib.gov.tr/efaturaozelentegratorlerlistesi.html)) but not whether it is mandatory
  for a tool in Kargah's position.
- The current, up-to-date e-Fatura and e-Arsiv revenue thresholds. Sources directly disagreed: one
  gave a TRY 3 million e-Fatura threshold (stated as the 2022 fiscal year figure) with an e-Arsiv
  threshold of TRY 30,000 lowering to TRY 10,000 ([vatcalc.com](https://www.vatcalc.com/turkey/turkey-e-invoice-e-fatura-and-e-arsiv-update/)); another gave e-Arsiv thresholds of TRY
  5,000 (non-taxable recipients) and TRY 2,000 (taxable recipients) with no mentioned reduction
  ([dddinvoices.com](https://dddinvoices.com/learn/e-invoicing-turkey)). Any implementation should read current figures from GIB directly, not
  from this document.
- The exact scope of the 80% income-tax exemption for freelance/self-employed exporters of
  "technical services" — which service categories qualify was not detailed in the sourced material
  ([masen.com.tr](https://www.masen.com.tr/en/the-80-tax-exemption-on-service-exports-in-turkey/), [a-m.com.tr](https://a-m.com.tr/tax-exemption-for-turkeys-digital-exporters/)).
- Whether the 20% stopaj withholding obligation (Income Tax Law Art. 94) applies when the payer is
  a foreign client rather than a Turkish tax-liable organization — the sourced material describes
  the obligation only for Turkish payers, and this is the case most relevant to Kargah's
  USD-invoicing scenario.
- Turkish tax treatment of a USDT (stablecoin) receipt by a freelancer: whether it is treated as a
  foreign-currency receipt (valued at the CoinGecko/market rate the codebase already uses) or as a
  crypto-asset disposal event with separate reporting rules. No source in this research addressed
  this directly; flagged as the highest-risk open question given the existing USDT support in the
  codebase.
- Whether Turkish tax rules treat realized foreign-exchange gains/losses on a freelancer's invoices
  as taxable income / deductible expense, and how that interacts with the gecici vergi (provisional
  tax) calculation. General (non-Turkey-specific) sources confirm this varies by jurisdiction but
  none addressed Turkey specifically ([finally.com](https://finally.com/blog/accounting/foreign-currency-small-business-accounting/)).
- Plaid or equivalent bank-feed aggregator coverage for Turkish banks — not addressed by any source
  in this research; relevant only if bank feeds are reconsidered later (see section 9).
- Whether an automatic percentage-based "late fee" feature (as distinct from payment reminders) is
  something freelancers in the general (non-Turkey) source material actually use, versus something
  vendors list but rarely enable — no source directly addressed usage frequency.
