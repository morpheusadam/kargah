# Kargah

Kargah is a self-hosted Laravel application that combines email, project boards, accounting and a data vault behind one login, aimed at solo freelancers and small studios.

[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Livewire](https://img.shields.io/badge/Livewire-4-4E56A6?logo=livewire&logoColor=white)](https://livewire.laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Shared hosting](https://img.shields.io/badge/runs%20on-shared%20hosting-success)](#requirements)

## Overview

Kargah (Persian: کارگاه, *workshop*) puts four tools that freelancers normally run as separate
subscriptions — a mail client, a Trello-style board, invoicing and a password/file store — into a
single Laravel application with one database and one session.

It is built to run on ordinary PHP shared hosting rather than on a VPS with Redis and Supervisor.
Background work goes through a database-backed queue driven by a plain cron entry.

## Requirements

- PHP 8.3+ with `bcmath`, `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo`, `zip`
- MySQL 8.0+ / MariaDB 10.6+ (or SQLite)
- Composer 2
- Cron access (one entry, once a minute)
- No shell daemon, no Redis, no Node.js on the server

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

## Features

### Projects

Independent boards you create and name yourself, with custom lists, drag-and-drop cards, labels,
checklists, due dates, attachments and comments.

### Mail

- Unified inbox over IMAP, synced into the local store by a background job so pages do not block
  on a network round-trip.
- Bulk campaigns routed across multiple delivery providers (Brevo, Resend, Amazon SES, Mailgun,
  SMTP2GO and others) with per-provider daily quotas, health scoring and automatic failover.
- Shared suppression list — a hard bounce on one provider blocks that address on all of them.
- Campaign replies thread back to the right contact via signed `Reply-To` tokens.

### Accounting

Invoices with estimates and recurring billing, categorised expenses, a client book, and monthly
profit and loss reports.

### Data

Files, an encrypted credential vault, saved links and Telegram bots, GitHub repositories pulled
through the API, and scheduled backups stored outside the web root.

### Social

Notifications from every connected network in a single stream, and a composer that publishes one
post to several networks at once with per-network character limits enforced live.

## Architecture

Each area is a self-contained [`nwidart/laravel-modules`](https://github.com/nWidart/laravel-modules)
package with its own routes, views, migrations and Livewire components:

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

The admin theme assets referenced under `public/assets/` are not part of this repository and are
not covered by this license. You must hold your own license for whichever template you use.
