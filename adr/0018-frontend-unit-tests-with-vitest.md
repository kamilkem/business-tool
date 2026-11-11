# 0018. Frontend unit tests run with Vitest, in a browser time zone that is not the organization's

Status: Accepted

Date: 2026-11-11

## Context

Until now the frontend had no tests: CI typechecked, linted and built it (ADR
[0011](0011-continuous-integration-on-github-actions.md)), and screens were checked by hand in the
browser. Showing dates and times in the organization's time zone (#20,
ADR [0019](0019-frontend-dates-in-the-organizations-time-zone.md)) is the first frontend code whose
mistakes a type checker cannot catch and a manual check rarely sees: a helper that uses the
browser's zone looks right on a laptop in Warsaw and is wrong on a phone set to another zone, on a
day the clocks change, or at midnight on the last day of a month. Payroll and income screens will
add more of the same arithmetic.

## Decision

1. **Vitest** is the frontend's unit test runner. It is a dev dependency of the workspace root
   (`frontend/package.json`), with one config, `frontend/vitest.config.mts`, that picks up
   `*.test.ts` and `*.test.tsx` under `admin/src`, `timeclock/src` and `packages/*/src`.
2. **Unit tests cover pure code**: formatting, date arithmetic, validation and similar helpers. Tests
   sit next to the code they test (`date-time.ts` → `date-time.test.ts`) and run in the `node`
   environment. A test that needs a DOM or a component render would add an environment
   (`jsdom`/`happy-dom`) to this config, as a separate decision once it is needed.
3. **The tests run with `TZ=America/New_York`**, set in the config, so the machine's zone is never
   the organization's (`Europe/Warsaw` in most tests). Code that silently uses the browser's zone
   fails there, wherever the tests run.
4. **Test names follow the backend's**: `it("test_it_...")` sentences.
5. **Gates**: `pnpm test` in `frontend/` (also `make test-frontend`) runs them; `make qa` and the CI
   *Frontend* job run them after lint. The PR guard's "no net drop in tests" counts vitest
   `it(`/`test(` cases as well as PHPUnit methods, and it already rejects `it.skip(` and friends.

## Alternatives considered

- **`node:test` with Node's type stripping.** No new dependency, but the workspace's imports
  (`@business-tool/ui/lib/*` through package exports, `@/` aliases, `.tsx`) would need loaders or
  path rewrites that Vite already resolves. Vitest reuses the apps' Vite and TypeScript setup.
- **Jest.** Needs its own TypeScript and ESM transform setup next to Vite's; Vitest is the
  standard choice for Vite projects.
- **No tests, browser checks only.** Rejected for the reason above: the failures depend on the
  machine's zone and the date.

## Consequences

- One more dev dependency and one more CI step (a few seconds).
- Frontend helpers with rules get tests in the same change, like backend code.
- Component and end-to-end tests are not covered by this decision.
