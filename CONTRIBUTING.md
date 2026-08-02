# Contributing to Kargah

Thanks for looking. Kargah is a small, opinionated project — a pull request that matches the
existing shape gets merged quickly; one that reinvents a convention usually does not.

## Before you start

Open an issue describing what you want to change and wait for a reply. This is not bureaucracy:
several areas have a design already agreed, and it is unpleasant to reject work that was correct
but aimed at the wrong target.

## Setting up

```bash
git clone https://github.com/morpheusadam/kargah.git
cd kargah
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

The UI expects a Tailwind admin theme in `public/assets/` — see [`docs/theme.md`](docs/theme.md).
Without it the app works but is unstyled.

## The rules that matter

1. **Read [`docs/frontend-conventions.md`](docs/frontend-conventions.md) before writing Blade.**
   It is binding, not advisory.
2. **Stay inside one module.** A change that spans modules usually means the boundary is wrong;
   say so in the issue instead of reaching across.
3. **Every route gets a smoke test.** Add it to `tests/Feature/SmokeTest.php`.
4. **Run the tests before you push:**
   ```bash
   php artisan test
   ```
5. **No new dependencies without discussion.** Kargah has to run on cheap shared hosting; each
   package is a constraint on who can deploy it.

## Adding a module

```bash
php artisan module:make YourModule
composer dump-autoload
```

Then register its Livewire namespace in `config/livewire.php`, add its routes in
`Modules/YourModule/routes/web.php`, and add one navigation group in
`resources/views/partials/sidebar.blade.php`.

## Commit messages

Conventional commits, imperative mood, and a body that says *why* rather than *what*:

```
feat(mailbox): route campaign sends across providers by remaining quota

Sending everything through one provider burns its daily allowance and
silently drops the tail of a campaign. The router now picks the healthiest
provider with quota left and falls back on a 4xx.
```

## What gets rejected

- Reformatting untouched files
- Adding a build step to the deployment path
- Anything that requires a daemon, Redis, or shell access at runtime
- Secrets, tokens or personal data in fixtures
