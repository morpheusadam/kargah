# 07 — Platform: application passwords, an API, and the assistant

**Status:** requirements, 3 August 2026. Nothing here is built.

Kargah is currently a website a person clicks. This document turns it into something a *program*
can drive — the owner's own scripts, a CLI, and an assistant — without giving any of them the
owner's password.

---

## Why application passwords, and why WordPress's shape

WordPress solved this problem well and the shape is worth copying outright: a user generates a
named credential, sees the secret **once**, and uses it with HTTP Basic auth against the REST API.
Revoking it does not touch the user's own password, and every credential records when it was last
used and from where.

The properties that matter, in order:

1. **The secret is shown once and stored hashed.** If it can be read back out of the database, it
   is not a credential, it is a password lying in a table. Hash it with the same driver the user
   password uses.
2. **It is named, and it is revocable individually.** "Laptop CLI", "the assistant", "the backup
   script" — so revoking one does not break the others.
3. **It carries scopes.** WordPress does not do this and regrets it; Kargah should. A token that
   can read the boards has no business reading the vault.
4. **Last used at, last used IP, and an activity entry on creation and revocation.** A credential
   nobody can audit is a credential nobody can trust.
5. **It never appears in a rendered page after creation.** `tests/Feature/NoSecretsInHtmlTest.php`
   already plants a canary in every secret-bearing column and walks every page; the new table will
   be picked up by it automatically because the column will be named `*_hash` and `token`.

```
application_passwords
  id
  user_id        fk -> users.id
  name           varchar(120)          -- what the owner called it
  token_hash     varchar(255)          -- hashed, never the secret
  prefix         varchar(12)           -- first characters, shown so a row is identifiable
  scopes         json                  -- ['project:read', 'accounting:read', …]
  last_used_at   datetime null
  last_used_ip   varchar(45) null
  expires_at     datetime null         -- optional; a credential that never expires is a decision
  revoked_at     datetime null
  created_at, updated_at
```

**Scopes** are `module:action` — `project:read`, `project:write`, `accounting:read`,
`mailbox:send`, `data:reveal`, and so on. `data:reveal` is deliberately separate from `data:read`:
listing the vault and decrypting an entry are different powers.

Authentication is HTTP Basic, username = the user's email, password = the application password —
so `curl -u` works, which is the whole point. Laravel Sanctum can back this, but the *interface*
must be Basic auth, not a bearer token, because that is what makes it usable from a shell in one
line.

**Rate limit it**, and log a failed attempt. This endpoint is the only thing in Kargah reachable
without a session.

---

## The API

There is currently no HTTP API — thirty scaffolded endpoints were removed in phase 7 precisely
because they were dead. What replaces them must be written deliberately.

Rules:

- **Read the contracts, not the models.** `Modules\Project\Contracts\CardReader`,
  `Modules\Core\Contracts\CustomerReader`, `Modules\Accounting\Contracts\…` — the API is another
  consumer of the same contracts the modules already expose to each other. This is the test of
  whether those boundaries were real.
- **Every endpoint declares the scope it needs.** No endpoint is reachable without one.
- **Money is a string in JSON, never a number.** A JSON number is a double in every client that
  parses it, and `03-accounting.md` explains at length why that is not acceptable. Amounts go out
  as `{"amount": "1500.000000", "currency": "USD", "formatted": "$1,500.00"}`.
- **Cursor pagination**, matching what the pages already do.
- Answer `/api/v1/whoami` with the token's name and scopes, so a client can tell what it may do
  without guessing.

Surface, in priority order: boards, lists, cards (read and write, including move); customers and
companies; invoices and expenses (read, and issue as an explicit action); emails (read, send);
credentials (list; reveal separately scoped); search across everything.

---

## The assistant

An assistant inside Kargah that can actually *do* things — read the boards, draft an invoice,
summarise an inbox — rather than one that chats.

### Providers

One driver interface, several providers, and the ones with a free tier first:

| Provider | Note |
| --- | --- |
| **Google Gemini** | A genuinely usable free tier. Good default. |
| **OpenRouter** | One key, many models, several free. The pragmatic choice for breadth. |
| **Anthropic Claude** | Paid; the best at tool use, which is what this feature is. |
| **OpenAI** | Paid. |
| **Ollama / LM Studio** | A local endpoint. No key, no cost, works offline. |

This is exactly the shape `Modules\Accounting\app\Services\RateSources` and
`Modules\Mailbox\app\Services\Delivery` already use: an interface, a driver per provider, a
registry of *factories* so a real driver is never constructed in a test, and a fake. Follow it —
three modules doing the same thing the same way is a pattern; four is a convention.

Keys are encrypted with the application key, in `$hidden`, encrypted **inside the setter** —
see the note in `DECISIONS.md` about the mutator idiom that silently stores clear text.

### Tools

The assistant reaches Kargah through the same contracts the API does, with the same scopes. It
does not get privileged access; it gets an application password like anything else, which means
its powers are visible on the settings page and revocable in one click.

Start with: search, read a board, create a card, move a card, read an invoice, draft an invoice,
summarise a thread. **Anything that spends money or sends mail asks first** — drafting an invoice
is a tool, issuing one is not. That is the same line `InvoiceIssuer` already draws and the same
reason `accounting:generate-recurring` creates drafts and never issues.

### Streaming on shared hosting

Server-sent events over PHP-FPM work but hold a worker for the duration of the response, and on
shared hosting workers are the scarce resource. Default to a non-streaming request with a clear
pending state. Offer streaming as a setting for installs that can afford it, and say what it
costs.

### The CLI

`php artisan kargah:ask "what is overdue?"` — the same tools, from the terminal, authenticated by
the same application password. This is what makes the feature usable from a script and from an
external agent, and it costs almost nothing once the tool layer exists.

---

## Notifications — the toast layer

The front end currently toasts on *everything*, including opening and closing a panel. There are
28 `toastSuccess` calls in the card drawer alone. That was right during the front-end phase, when
every interaction had to visibly report; it is wrong now.

- **Only report what the user cannot already see.** A panel opening is visible — the panel is
  open. A card saving, an invoice issuing, a campaign starting, anything failing: those are worth
  a toast.
- **Default duration 3 seconds**, down from 5. `resources/views/partials/toasts.blade.php`,
  `DEFAULT_DURATION`. Errors keep their longer life — an error that vanishes in three seconds is
  not a warning, it is a rumour.
- The rule stays: **never a success toast on a method that does nothing.**
