<div align="center">

# Kargah

**The self-hosted freelance workspace — inbox, boards, invoices and vault in one Laravel app.**

Kargah (Persian: کارگاه, *workshop*) is an open-source, self-hosted dashboard for solo
freelancers and small studios. It puts the four tools you actually switch between all day —
**email**, **a real Trello-style board**, **accounting** and **a data vault** — behind a single
login, on hosting you already pay for.

[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Livewire](https://img.shields.io/badge/Livewire-4-4E56A6?logo=livewire&logoColor=white)](https://livewire.laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Shared hosting](https://img.shields.io/badge/runs%20on-shared%20hosting-success)](#requirements)

</div>

---

## Why Kargah exists

Every freelancer ends up running the same stack: a mail client, Trello, an invoicing SaaS, a
password manager and a notes app. Five logins, five subscriptions, and no connection between
them — you cannot turn an email into a task, or a finished task into an invoice line.

Kargah is that connection. One database, one session, one deployment.

It is built to run on **ordinary PHP shared hosting** (the kind that costs a few dollars a month),
not on a VPS with Redis and Supervisor. Everything heavy runs through a database-backed queue
driven by a plain cron job.

## Features

### 📋 Projects — a real Trello, not a status board
Independent boards you create and name yourself. Custom lists, drag-and-drop cards, labels,
checklists, due dates, attachments and comments. Not a task list with a kanban view bolted on.

### 📧 Mail — inbox *and* bulk sending
- **Unified inbox** over IMAP, synced into the local store by a background job so pages never
  block on a network round-trip.
- **Bulk campaigns** routed across multiple delivery providers (Brevo, Resend, Amazon SES,
  Mailgun, SMTP2GO…) with per-provider daily quotas, health scoring and automatic failover.
- **Shared suppression list** — a hard bounce on one provider blocks that address on all of them.
- Campaign replies thread back to the right contact via signed `Reply-To` tokens.

### 💰 Accounting — invoices, expenses, clients, reports
Invoices with estimates and recurring billing, categorised expenses, a client book, and reports
that answer the only question that matters: did this month make money.

### 🗄 Data — everything you would otherwise lose
Files, an encrypted credential vault, saved links and Telegram bots, your GitHub repositories
pulled through the API, and scheduled backups stored outside the web root.

### 📣 Social — one feed, one composer
Notifications from every connected network in a single stream, and a composer that publishes
one post to several networks at once with per-network character limits enforced live.

## Architecture

Kargah is **modular by construction**. Each area is a self-contained
[`nwidart/laravel-modules`](https://github.com/nWidart/laravel-modules) package with its own
routes, views, migrations and Livewire components:

```
Modules/
├── Project/      boards, lists, cards
├── Accounting/   invoices, expenses, clients, reports
├── Mailbox/      IMAP inbox, campaigns, contacts, providers
├── Data/         files, vault, links, repos, backups
└── Social/       notifications, publishing, accounts
```

Deleting a module directory removes that feature. Adding one requires no change to the app shell
beyond a single navigation entry.

## Tech stack

| Layer | Choice | Why |
| --- | --- | --- |
| Framework | Laravel 13 | Scheduler, queues and Eloquent out of the box |
| UI | Livewire 4 (single-file components) | Reactive pages without a SPA build step |
| Modules | nwidart/laravel-modules | Real isolation, not folders |
| Mail receive | `webklex/php-imap` | Pure PHP — no `ext-imap` needed on shared hosting |
| Mail send | Provider APIs over HTTPS | Never the host's own mail server |
| Database | MySQL 8 / MariaDB (SQLite for dev) | Whatever your host gives you |
| Queue | Database driver + cron | No daemon, no Redis, no Supervisor |

## Requirements

- PHP **8.3+** with `bcmath`, `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo`, `zip`
- MySQL 8.0+ / MariaDB 10.6+ (or SQLite)
- Composer 2
- Cron access (one entry, once a minute)
- **No** shell daemon, **no** Redis, **no** Node.js on the server

## Installation

```bash
git clone https://github.com/morpheusadam/kargah.git
cd kargah

composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate

# configure DB credentials in .env, then:
php artisan migrate --seed
php artisan storage:link
```

Point your web root at `public/` and add one cron entry:

```
* * * * * cd /path/to/kargah && php artisan schedule:run >> /dev/null 2>&1
```

### Front-end assets

Kargah ships without a bundled admin theme. The UI is written against a Tailwind admin template
which you supply yourself — drop its compiled assets into `public/assets/`. See
[`docs/theme.md`](docs/theme.md) for the expected structure and a list of MIT-licensed
alternatives if you do not own a commercial template.

## Roadmap

- [x] Modular application shell, auth, navigation
- [x] Front-end for all five modules
- [ ] Board persistence and card detail drawer
- [ ] IMAP sync job and message store
- [ ] Campaign queue, provider router and suppression list
- [ ] Invoice PDF generation
- [ ] Encrypted vault with per-item reveal auditing
- [ ] GitHub and Telegram integrations
- [ ] Lead crawler

## Contributing

Issues and pull requests are welcome. Please open an issue before starting significant work so
the design can be agreed first.

## License

[MIT](LICENSE) © Kargah contributors.

The admin theme assets referenced under `public/assets/` are **not** part of this repository and
are not covered by this license. You must hold your own license for whichever template you use.

---

<div align="center">
<sub>Keywords: self-hosted freelance dashboard · Laravel CRM · open source invoicing · IMAP inbox PHP ·
bulk email Laravel · Trello alternative PHP · Livewire admin panel · freelancer project management ·
shared hosting Laravel app</sub>
</div>
