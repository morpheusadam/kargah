# Decisions

Judgement calls made while building, where the spec was silent or turned out to be wrong. One line
of reasoning each, so nobody has to reconstruct it later.

---

## Phase 1 — Core

**`companies.default_currency` is a plain string, not a foreign key.**
The spec's data model implies a currency table, but that table belongs to Accounting, and Core may
not depend on a feature module. Accounting validates the value when it reads it. This is the first
concrete case of the "foreign keys point at Core, never sideways" rule applying to Core itself.

**Core's migrations are timestamped `2026_01_01_*`, and that — not module priority — is what
guarantees ordering.**
The spec warns that `php artisan migrate` ignores module priority. That is true, but the practical
consequence is milder than the spec implies: nwidart registers module migration paths with the
framework, so plain `migrate` runs them in filename order alongside the app's own. Giving Core the
earliest timestamps makes the correct order hold under *both* commands. Priority is set as well
(Core `0`, features `10`), as belt and braces.

**`module:migrate` is interactive and cannot be used unattended.**
It prompts for module selection and aborts on a non-interactive shell. Use
`php artisan module:migrate --all --force`, or plain `php artisan migrate --force` given the
timestamp rule above. The deploy script must not call bare `module:migrate`.

**The morph map is enforced from `booted`, not from `boot`.**
Each module registers its own aliases in its own `boot()`. Core has priority 0, so Core boots
first; calling `requireMorphMap()` there would fire before the feature modules had registered
anything. Deferring to `$this->app->booted()` lets every module have its turn, and still fails
loudly at the first polymorphic write.

**Links are undirected on read, directed on write.**
A link row records which model created it, but `linked()`, `isLinkedTo()` and `unlinkFrom()` all
look at both ends. Asking a card what it is linked to should return an invoice regardless of which
side created the row — anything else pushes an arbitrary ordering decision onto every caller.

**`linkTo()` is idempotent.**
It is `updateOrCreate` on the full pair plus relation, matching the unique index. Linking the same
two things twice updates the metadata rather than failing or duplicating. Jobs that run twice —
which is the whole cron design — must not produce two links.

**`spatie/laravel-activitylog` resolved to 4.12.3, not v5.**
Research reported v5 as current. Composer resolved 4.12.3 against this Laravel version. The schema
and API used here are the same in both; no change needed, but the version in the spec was wrong.

**The full-text index is created per driver, and SQLite gets none.**
MySQL/MariaDB get `FULLTEXT`, PostgreSQL gets a GIN index over `to_tsvector`, SQLite gets neither
because it has no equivalent in the default build. Scout's database engine falls back to `LIKE`
there, which is correct for development and irrelevant in production.
