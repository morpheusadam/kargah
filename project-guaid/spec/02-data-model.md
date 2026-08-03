# 02 — Data model

The thing that makes Kargah worth building is not that it has five modules. It is that the five
share one graph: an email becomes a card, a card becomes an invoice line, a file attaches to any of
them, and all of it hangs off a customer.

This document defines that graph.

---

## The spine: Core

One module every other module depends on. It depends on nothing.

```
Core
├── companies
├── customers
├── links          ← the "anything to anything" pivot
├── activities     ← one timeline across every module
└── searchables    ← one search index across every module
```

### Why a shared Core rather than duplicated entities

Two defensible patterns exist. Duplicating a customer into each module and syncing by event is the
right answer when modules will eventually become separate services owned by separate teams —
Medusa.js does exactly this, with no foreign keys between modules at all. A shared kernel is the
right answer when the application is one deployable owned by one person and always will be —
Akaunting does this, with Company as a first-class entity other modules key off directly.

Kargah is the second case. Duplicating customers here would pay the microservices tax without
receiving any microservices benefit, and would introduce a class of bug — stale copies — that
cannot otherwise happen.

### companies and customers

A **Company** is a legal entity you bill or are billed by. A **Customer** is a person you deal
with. A customer may belong to a company, or may be an individual with no company at all.

```
companies
  id
  name                  varchar(190)
  legal_name            varchar(190) null    -- what goes on the invoice, if different
  tax_number            varchar(50)  null    -- VKN / VAT ID / EIN
  tax_office            varchar(120) null    -- Turkish invoices need "vergi dairesi"
  country               char(2)              -- ISO 3166-1 alpha-2
  address               text null
  default_currency      varchar(10)  null -> currencies.code
  is_domestic           boolean              -- drives Turkish TL-equivalent rules, see 03
  website               varchar(190) null
  notes                 text null
  archived_at, timestamps, deleted_at

customers
  id
  company_id            fk -> companies.id  nullable, nullOnDelete
  name                  varchar(190)
  email                 varchar(190) null   index
  phone                 varchar(50)  null
  role                  varchar(120) null   -- "Head of Marketing"
  avatar_path           varchar(255) null
  timezone              varchar(64)  null
  notes                 text null
  archived_at, timestamps, deleted_at
```

`customers.email` is indexed because Mailbox resolves an incoming message to a customer by
matching the sender address. That is the join that turns an inbox into a CRM.

### links — anything to anything

One generic pivot rather than fifteen explicit ones. At Kargah's scale — one user, tens of
thousands of rows — the flexibility is worth more than the referential integrity a typed pivot
would give, and building fifteen pivot tables for a single-user application is ceremony.

```
links
  id
  source_type    varchar(60)   \
  source_id      bigint         |  composite index (source_type, source_id)
  target_type    varchar(60)   |
  target_id      bigint        /   composite index (target_type, target_id)
  relation       varchar(40)       -- 'converted_to' | 'billed_as' | 'references' | 'attached_to'
  meta           json null
  created_by     fk -> users.id
  created_at
  unique (source_type, source_id, target_type, target_id, relation)
```

Examples of what this carries:

| source | target | relation |
| --- | --- | --- |
| email | card | `converted_to` |
| card | invoice_line | `billed_as` |
| repository | board | `references` |
| post | card | `planned_from` |

**Morph aliases are mandatory.** `source_type` never stores a fully-qualified class name. Core's
service provider registers a hand-maintained map:

```php
Relation::enforceMorphMap([
    'company'  => \Modules\Core\Models\Company::class,
    'customer' => \Modules\Core\Models\Customer::class,
    'card'     => \Modules\Project\Models\Card::class,
    'invoice'  => \Modules\Accounting\Models\Invoice::class,
    'email'    => \Modules\Mailbox\Models\Email::class,
    // …
]);
```

`enforceMorphMap` rather than `morphMap` so an unregistered model throws instead of silently
writing a class name that a later rename will orphan. Automatic discovery packages exist; do not
use one — discovery order is not stable across module changes, and this table outlives refactors.

### activities — one timeline

Modules do not write into each other. Each fires domain events; Core listens and writes one row.

```
activities
  id
  subject_type, subject_id     -- morph, indexed
  causer_id      fk -> users.id nullable
  event          varchar(60)   -- 'invoice.issued', 'card.moved', 'email.received'
  description    varchar(255)
  properties     json null     -- before/after, amounts, whatever the event carried
  created_at                   -- index; no updated_at, rows are immutable
```

Use `spatie/laravel-activitylog` (v5, released March 2026, actively maintained) rather than
hand-rolling. Its schema is this shape already.

### searchables — one search box

Scout's **`database` driver** is the answer for shared hosting: it uses the database's own
full-text index, needs no daemon, and therefore no Meilisearch or Typesense.

Rather than making five models searchable separately, Core owns one denormalised table that every
module feeds by listener. One query, one relevance ranking, results from everywhere.

```
searchables
  id
  subject_type, subject_id     -- morph, unique together
  title          varchar(255)
  body           text          -- FULLTEXT (MySQL) / tsvector GIN (PostgreSQL)
  context        varchar(120)  -- "Invoice · Northwind Ltd"
  url            varchar(255)
  occurred_at    datetime null -- lets results sort by recency
  updated_at
```

## Module tables

Only cross-module relationships are specified here. Each module's internal tables are its own
business, detailed in its build phase.

### Project

```
boards           id, name, colour, company_id? -> companies.id, archived_at
board_lists      id, board_id, name, position
cards            id, title, description,
                 customer_id? -> customers.id,
                 due_at, completed_at, archived_at
card_placements  id, card_id, board_list_id, position, is_origin, created_by
card_labels      card_id, label_id
checklists / checklist_items / card_comments / card_members
```

**A card does not hold a list, and it does not hold a position.** Both live on `card_placements`,
which is what lets one card appear on two boards — see the mirror-cards decision at the top of
[06-trello-parity.md](06-trello-parity.md). `unique (card_id, board_list_id)` means a card sits in
a list once or not at all, and exactly one placement per card carries `is_origin`.

`card_placements.position` is a `decimal(20,10)`, not an integer. Reordering by rewriting every
row's integer position is O(n) writes per drag; picking a value midway between neighbours is one
write. Rebalance only when the gap gets too small to halve. Position belongs to the placement
rather than the card precisely because a mirror has its own place in its own list.

### Accounting

Full detail in [03-accounting.md](03-accounting.md). Cross-module facts:

```
invoices.company_id   -> companies.id     (who is billed)
invoices.customer_id  -> customers.id     (who to chase)
expenses.company_id   -> companies.id     nullable (the vendor)
```

A card becomes an invoice line through `links`, not a foreign key — a line may come from a card, a
timesheet, or nothing.

### Mailbox

```
mail_accounts     id, name, imap_host, imap_port, encrypted_password, sync_cursor, last_synced_at
emails            id, mail_account_id, thread_id, message_id (unique), in_reply_to, subject,
                  from_name, from_email, to, cc, body_text, body_html, has_attachments,
                  customer_id? -> customers.id,     ← resolved by matching from_email
                  is_read, is_starred, folder, received_at
email_threads     id, subject, participants json, last_message_at, message_count
campaigns / campaign_recipients / delivery_providers / suppressions
```

`emails.message_id` is unique so a re-sync never duplicates. That single constraint is what makes
the IMAP job safe to re-run, which is what makes it safe to run from cron.

### Data

Owns storage. Nothing else touches a disk.

```
attachments
  id
  attachable_type, attachable_id    -- morph: a card, an invoice, an email, anything
  disk, path, original_name, mime, size_bytes, checksum
  uploaded_by, created_at

credentials    id, name, username, secret_encrypted, totp_encrypted, url, category_id, company_id?
bookmarks      id, title, url, kind, notes, tags json     ← Telegram bots, deployed projects
repositories   id, provider, full_name, description, language, stars, is_private, synced_at
backups        id, target, disk, path, size_bytes, checksum, status, completed_at
```

Other modules attach files through `Modules\Data\Contracts\AttachmentService`, never by writing to
storage themselves. That is what keeps disk configuration in one place.

### Social

```
social_accounts   id, network, handle, credentials_encrypted, company_id?, connected_at
posts             id, body, media json, status, scheduled_for, published_at, company_id?
post_targets      id, post_id, social_account_id, body_override, status, remote_id, error
```

## Rules that hold everywhere

**Foreign keys point at Core, never sideways.** `invoices.customer_id → customers.id` is fine.
`invoices.card_id → cards.id` is not — that goes through `links`.

**Money is `decimal(20,6)` with a currency column.** Never float. See [03](03-accounting.md).

**Soft deletes on anything a person created.** Boards, cards, invoices, emails, files. Not on
append-only tables — activities and ledger entries are never deleted.

**Every table a person edits carries `created_by`.** Multi-user is coming and retrofitting it is
miserable.

**Timestamps are UTC in the database, rendered in the user's timezone.** The exception is
`invoices.issued_at`, which carries a date, not an instant: an invoice issued on 31 July is issued
on 31 July regardless of where it is read.

## Migration order

Core migrates first. Set `"priority": 0` in `Modules/Core/module.json` and `10` in every other.

Then the trap: **`php artisan module:migrate` respects priority; plain `php artisan migrate:fresh`
does not.** It falls back to filename order and will fail on Core's foreign keys. Put
`module:migrate` in the deploy script, the README and `CONTRIBUTING.md`, and never type the other
one.
