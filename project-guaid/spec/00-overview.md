# Kargah — Backend Specification

**Status:** draft for build · **Date:** 2 August 2026 · **Front end:** complete, 43 pages, 54 tests passing

---

## What this is

Kargah is a self-hosted workspace for one freelancer. It replaces the five subscriptions a solo
developer normally runs — a mail client, Trello, an invoicing SaaS, a password manager, a social
scheduler — with one Laravel application on hosting they already pay for.

The front end exists and works. This specification covers the back end: the data model, the
runtime, and the rules that keep five modules from turning into one tangle.

## The one decision everything else follows from

**Kargah must run on ordinary PHP shared hosting.** Not a VPS, not Docker, not a machine with a
daemon you can restart. That single constraint decides almost every technical choice in these
documents, and it is worth stating plainly because it is a constraint most Laravel advice ignores:

- No Redis → the database is the cache, the queue and the lock store.
- No long-running worker → cron dispatches short, resumable jobs.
- No Octane, no FrankenPHP worker mode, no Swoole → these cannot bind a port on shared hosting.
- No Node on the server → assets are built locally and uploaded.

A VPS upgrade path exists and is designed for, but nothing may *require* it.

## Principles

1. **Correct beats clever.** An invoice that shows the wrong number is worse than one that renders
   slowly.
2. **The database is the source of truth, not the UI.** Every figure on a page traces to a row.
3. **Modules own their data and expose contracts.** Reaching into another module's Eloquent model
   is the thing that turns a modular monolith back into a monolith.
4. **Money is never a float.** Ever. See [03-accounting.md](03-accounting.md).
5. **Nothing is deleted.** Soft deletes and an activity trail. A freelancer who loses an invoice
   loses money.
6. **Every heavy operation is resumable.** A job that dies halfway through a 300-recipient campaign
   must not resend the first 150.

## Module map

```
Core          ← the only shared dependency. Company, Customer, links, activity, search.
  ├── Project      boards, lists, cards
  ├── Accounting   invoices, expenses, payments, currencies
  ├── Mailbox      IMAP inbox, campaigns, delivery providers, suppression
  ├── Data         files, credentials, bookmarks, repositories, backups
  └── Social       accounts, posts, scheduling
```

Core depends on nothing. Every feature module depends on Core and on no other feature module.
Cross-module behaviour travels by event; cross-module data travels by contract or through Core's
generic link table.

## Documents

| File | Covers |
| --- | --- |
| [01-architecture.md](01-architecture.md) | Runtime, PHP, database, queue, cache, module boundaries |
| [02-data-model.md](02-data-model.md) | Core spine, Company/Customer, links, activity, search, per-module tables |
| [03-accounting.md](03-accounting.md) | Money storage, USD/TRY/USDT, exchange rates, invoicing |
| [04-frontend.md](04-frontend.md) | What to adopt from 2026 browser and Livewire 4 capability |
| [05-build-order.md](05-build-order.md) | Phases, acceptance criteria, what ships when |

## What is already true

Do not re-derive these; they are measured, not assumed.

- Laravel **13.23**, Livewire **4.3**, `nwidart/laravel-modules` **13**, PHP **8.3.33**
- 43 Livewire page routes across 5 modules, 72 Blade files, 54 tests passing
- Theme is Metronic 9, dark by default, with a generated utility layer at
  `public/assets/css/kargah.css` — see `docs/theme.md`
- OPcache is enabled; page renders are 75–190 ms warm. Before it was enabled they were 2–4 seconds
- Everything on every page is currently a **static fixture**. No model, no migration, no query
  exists yet. That is what this specification is for.
