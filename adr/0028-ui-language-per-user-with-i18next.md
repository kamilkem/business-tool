# 0028. The UI speaks English and Polish: each user picks a language, i18next catalogs in the frontend, error codes from the API, e-mails in the recipient's language

Status: Accepted

Date: 2026-11-21

## Context

The first users are Polish bar staff, and the UI, the API's error messages and the e-mails are all
in English. ADR [0002](0002-locale-specifics-behind-organization-settings.md) keeps the product
open to other countries, so Polish must not be hard-coded either. Some facts shape the decision:

- `User` is global and can belong to several organizations, with different roles in each
  (ADR [0001](0001-multi-tenancy-shared-database-organization-filter.md)). A person's language goes
  with the person, not with the bar they happen to be looking at.
- Number, money and date formatting already goes through one switch, `useLocale()`. Until now that
  switch is the browser's locale (ADR [0019](0019-frontend-dates-in-the-organizations-time-zone.md)
  §4, ADR [0010](0010-money-minor-units-and-utc-timestamps.md)), so a Polish phone shows "12,50 zł",
  while the text around the amount is English.
- About 16,000 lines of TypeScript across `admin`, `timeclock` and `packages/ui` hold their strings
  inline. Shared components (sign-in, password forms, period picker, request errors) are used by
  both apps.
- API domain exceptions carry English `detail` messages with ids in them, and the admin already
  rewrites those with regular expressions to put names in (`payrollErrorMessage()`). 422 violations
  come from the Symfony validator.
- E-mails are Twig templates rendered by the queue worker, which has no request and therefore no
  request locale (ADR [0012](0012-transactional-email-smtp-async-queue.md)).

## Decision

1. **Languages: English and Polish.** English (`en`) is the source language: every string is
   written in English first, and English is the fallback. Adding a language means adding its
   catalogs and its entry in `LANGUAGES` (`@business-tool/ui/i18n/languages`) and in the API's
   language enum. Nothing else changes.
2. **The language is the user's own setting**, stored on the global `User` as a nullable language
   code and returned by `/me`. Every user, employees included, changes it in either app (the account
   menu). When it is not set, the apps use the first browser language they ship (`pl-PL` → `pl`),
   else English (`resolveLanguage()`). There is no organization default. Signed-out screens (sign
   in, set and reset password) have no user, so they use the language the app last showed on this
   device (kept in `localStorage`), else the browser's.
3. **Frontend library: i18next**, one instance per app, created in `packages/ui`
   (`@business-tool/ui/i18n/i18n`), with `react-i18next` (`useTranslation()`, `<Trans>`) in
   components. Pure helpers (`requestErrorMessage()`, `formatDuration()`) call `i18n.t()` directly.
   - **Namespaces:** `ui` for `packages/ui` (its shared components and helpers), and one per app
     (`admin`, `timeclock`), which the app adds with `addResourceBundle()` before rendering.
   - **Typed keys:** each app declares i18next's `CustomTypeOptions` with `ui` and its own
     namespace typed from the English JSON, and `packages/ui` declares it with `ui` only, so an
     unknown key or a missing interpolation value is a TypeScript error (`make qa`, CI typecheck).
   - **Plurals** use i18next's suffixes (`_one`, `_few`, `_many`, `_other`) over `Intl.PluralRules`,
     which Polish needs ("1 minutę", "3 minuty", "5 minut").
   - **Switching:** after `/me` loads, the app calls `i18n.changeLanguage()` and sets
     `<html lang>`. Catalogs are bundled, not fetched: they are small, and synchronous `init()` lets
     helpers translate outside React.
4. **Catalogs** are JSON files next to the code that uses them:
   `<app or package>/src/locales/<language>/<namespace>.json`, nested keys named by screen or
   component (`payroll.approve.title`), never by the English text. **Missing translations fail CI**:
   `packages/ui/src/i18n/catalogs.test.ts` (a Vitest test, so it runs in `pnpm test`, `make qa`
   and CI with no new gate) finds every catalog in the workspace and checks that every language has
   exactly English's keys, the plural forms its `Intl.PluralRules` needs, the same `{{placeholders}}`
   and no empty text. Together with typed keys, this means a key used in code exists in English,
   and every English key exists in every language.
5. **API errors are translated by the client from error codes. Validation messages are translated
   by the API.**
   - **Domain exceptions** get a stable code and primitive parameters
     (`income.register_name_taken`, `{ name }`), and the problem response carries them as `code`
     and `parameters` next to `detail`. `detail` stays English, for logs and developers. The apps
     translate codes in the `ui` namespace (`apiError.<code>`), so they can put names in instead of
     ids. An unknown code falls back to a translated message for its status, never to raw English
     shown as if translated. A test checks that every exception mapped in `exception_to_status` has a
     code.
   - **422 violations** are translated by the Symfony validator through `symfony/translation`, in
     the request's locale. Symfony ships Polish for its built-in constraints. Our own constraint
     messages go into `translations/validators+intl-icu.<language>.yaml`. Both apps send
     `Accept-Language: <language>` on every API call. `framework.enabled_locales` is `[en, pl]` and
     the request locale is taken from that header, defaulting to `en`.
6. **E-mails are in the recipient's language.** `TransactionalEmail` carries a `locale`, which the
   handler takes from the recipient's `User` (else English). The worker renders with
   `TemplatedEmail::locale()`, so `|trans` in `templates/email/` and the subject (a translation key
   with parameters, translated in the adapter) use it. An invited user who has never signed in gets
   the language the inviter chooses in the add-member form (defaulting to the inviter's own), and it
   becomes the new user's setting. Catalogs: `api/translations/email+intl-icu.<language>.yaml`. A
   `make` target runs `debug:translation --only-missing` for every language (exit 65 on a missing
   key) and `lint:translations` in `make qa` and CI. A test renders every template in every
   language.
7. **Formatting follows the UI language** (this supersedes point 4 of
   ADR [0019](0019-frontend-dates-in-the-organizations-time-zone.md)). Each app provides
   `<LocaleContext value={formattingLocale(language)}>`: English is `en-GB` (21/11/2026, 24-hour),
   Polish is `pl-PL`. `useLocale()`, `useDateTimeFormat()`, `useMoneyFormat()`, `<MoneyInput>` and
   `PeriodPicker` follow it. The time zone and the currency stay the organization's (ADR 0019 §3,
   ADR 0010). Words inside formatted values (`"7 h 30 min"`) move into the `ui` catalog.

## Alternatives considered

- **An organization default language, or only an organization language.** A bar's staff do not all
  speak the same language, a user can belong to several organizations, and an owner may want the
  admin in English while employees use Polish. The browser fallback covers a user who has never
  chosen.
- **Browser language only, no setting.** A shared or borrowed phone, or an English-language phone
  belonging to a Polish speaker, would decide for the user, and e-mails sent by the worker would
  have no language at all.
- **Lingui.** Its compile-time extraction from English source strings and its ICU messages are
  attractive, but its macros need a Babel or SWC transform, which `@vitejs/plugin-react` 6 and Vite 8
  no longer run by default, and every component (and Vitest) would then depend on that transform. i18next needs no
  transform, and with typed keys TypeScript catches the same mistakes.
- **react-intl (FormatJS).** ICU messages and good `Intl` alignment, but extraction and the
  missing-message check need its CLI and generated ids. It also only works inside React, while our
  pure helpers (request errors, durations) need translation outside components.
- **A home-grown `t()` over TypeScript message objects.** Fully typed parameters and no dependency,
  but we would maintain plurals, rich text, interpolation and language switching ourselves, and
  translators and translation tools could not work with TypeScript files.
- **Domain errors translated by the API (`Accept-Language`).** One place for all texts, but the
  messages hold ids that the client replaces with names it already knows (the admin does this
  today). Translated text would break that rewriting, and every new parameter would need a
  server-side lookup. Codes give the client the data. Violations are the exception: Symfony already
  translates them, and their parameters are the constraint's own.
- **Polish formats for everyone in a Polish organization (formatting follows the country).** The
  same English speaker would see different number formats depending on the bar. Formatting follows
  the language the person reads, and the organization still decides the time zone and the currency.

## Consequences

- Every string a user sees goes through a catalog: `t()` in the frontend, `|trans` in e-mails, a
  code for domain errors. Writing a feature means writing its English keys and its Polish
  translation in the same change, or the catalog test fails.
- Until the existing screens are extracted, the apps stay English. The work is split into issues:
  the user setting in the API, e-mails, error codes, wiring both apps, then the strings of
  `timeclock` and `packages/ui`, then `admin`. Only once both apps are wired does
  `<LocaleContext>` switch formatting from the browser's locale to the language's.
- `useLocale()` changes meaning from "the browser's locale" to "the locale of the language shown".
  A user whose phone is set to `pl-PL` but who chooses English sees `en-GB` formats.
- Polish plurals and grammatical cases must be handled in the translation (plural keys, separate
  keys rather than concatenated fragments), not in code.
- Domain exception messages stay English and are no longer shown to users once their codes are
  translated. The regex rewriting of ids in the admin is replaced by code parameters.
- The API gains `symfony/translation` and its catalogs, and the e-mail context gains a locale. A
  third language means translating both catalogs and adding the code in both enums. Both language
  lists are checked by their own tests, not by a shared source of truth.
- Catalogs are bundled per app in both languages. If they grow large, they can be split per route
  and loaded lazily without changing callers.
