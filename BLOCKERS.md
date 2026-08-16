## Unit 4

Settings panel. Nothing blocked the unit; four findings the orchestrator owns.

1. **No new route was needed, so none was added.** Every setting reached by the
   new settings-nav search is an in-page anchor on one of the six existing
   routes (`#identity`, `#regional`, `#password`, `#two-factor`, `#sessions`,
   `#theme`, `#colour-blind`, `#events`, `#delivery`, `#new`, `#issued`,
   `#providers`). `SmokeTest::pageProvider()` is untouched and needs no entry.

2. **Four Appearance controls were removed because nothing stored them.**
   `theme` survived (it is real — `layouts/app.blade.php` owns
   `window.kargahTheme` and the `kargah.theme` localStorage key), and
   `colour_blind_mode` survived (a real `users` column). Accent colour, row
   density, "start with the sidebar collapsed" and "reduce motion" were public
   properties on a component with no `save()`, no column and no listener: five
   clicks that reverted on reload. They are now listed on the page as not
   adjustable, each with an em dash for a value. Restoring any of them needs a
   migration on `users` plus something that reads it — a migration is outside
   this unit's file list, so it was not written.

3. **Nothing needed backing in `Modules\Core`.** `NotificationPreferences` and
   `NotificationSetting` already carry every switch the notifications page
   offers. The page regroups the events by task rather than by module, but does
   that in the view: `NotificationEvents` is untouched, and an event added there
   later falls into an "Everything else" group rather than disappearing.

4. **`Modules\Platform` now reads two constants from `Modules\Social`.** That
   coupling is deliberate and was required by the brief —
   `SocialAccount::TOKEN_EXPIRY_WARNING_DAYS`, `CheckTokenExpiry::WARN_AT_DAYS`
   and `RefreshTokens::REFRESH_AFTER` are read, never restated. Every read is
   guarded with `class_exists`, so switching Social off degrades the
   application-passwords near-expiry badge rather than fataling. If Platform is
   ever meant to be installable without Social, that constant needs a home
   neither module owns.


