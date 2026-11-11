# 0011. Continuous integration on GitHub Actions: the `make qa` gates on every PR, plus PR guards

Status: Accepted

Date: 2026-11-11

## Context

`make qa` runs every check locally (ADR hygiene, schema validation, Deptrac, PHPStan, PHPUnit, TS
typecheck, lint, builds), but nothing makes sure it was run before a merge. The repository lives on
GitHub. Some rules in `CLAUDE.md` ("fix the types; don't add ignores", migrations are not edited once
merged, `schema.d.ts` is generated and never edited by hand) are easy to break without anyone
noticing in review, by people or by Claude.

## Decision

1. **GitHub Actions** runs CI. Workflows live in `.github/workflows/`, guard logic in
   `.github/scripts/` as shell scripts that also run locally; a workflow step only calls them.
2. **`ci.yaml`** runs on every PR (except ADR- or Markdown-only ones) and on pushes to `main`. A
   `changes` job routes by path (`api/**`, `frontend/**`); a skipped job counts as passed.
   - *API static*: `composer validate`, `composer audit` (advisories fail, abandoned packages are only
     reported), `lint:yaml`, `lint:container`, PHPStan, Deptrac.
   - *API tests*: migrations on an empty PostgreSQL 17, `doctrine:schema:validate` against the
     migrated database, PHPUnit.
   - *Frontend*: `pnpm install --frozen-lockfile`, typecheck, lint, build, and a check that the
     generated `routeTree.gen.ts` files are committed.
   - *API types*: exports the OpenAPI document (`api:openapi:export`, no server or database
     needed), regenerates `schema.d.ts` from it and fails on a diff.
3. **Jobs run on the runner, not in the compose stack**: `setup-php` with the extensions of
   `api/Dockerfile`, Node 22, PostgreSQL 17 as a service. Versions are kept in step with
   `api/Dockerfile` and `compose.yaml` by hand.
4. **Dependencies are installed from the lock files** (`composer install`, `--frozen-lockfile`), so CI
   sees what `make qa` sees. Newer versions come only through Dependabot PRs (weekly, grouped per
   ecosystem; Symfony minor and major upgrades are done by hand).
5. **`pr-guards.yaml`** runs on every PR, whatever it touches:
   - `check-suppressions.sh`: no new PHPStan baseline or `ignoreErrors`, no inline
     `@phpstan-ignore`, lint or TypeScript suppressions, no skipped tests, no new Deptrac
     `skip_violations`, no net drop in test methods. The only escape hatch is the
     `intentional-exception` label on the PR, with the reason in the PR description and next to the
     suppression.
   - `check-merged-migrations.sh`: a migration that exists on the base branch is never modified,
     renamed or deleted; a fix is a new migration.
6. **`secret-scan.yaml`** runs gitleaks (the binary, pinned) on the PR's commits, and on the whole
   history on `main`.
7. **Third-party actions are pinned to a commit SHA** with the version in a comment; Dependabot
   updates them.
8. A new CI gate gets a local counterpart (a `make` target or a script) in the same change, so a
   failure can be reproduced without pushing.

## Alternatives considered

- **Run CI inside the compose stack** (`docker compose up`, then `make qa`): one source of truth for
  versions, but every run builds the FrankenPHP image and installs extensions (minutes per job), and
  the jobs can't run in parallel against one stack. Rejected for speed; the price is keeping versions
  in step by hand (point 3).
- **A version matrix** (several PHP or PostgreSQL versions): the application ships as one Docker
  image with one version of each, so a matrix would only test combinations that never run.
- **Behat**: not used in this project (PHPUnit API tests cover the endpoints, see `CLAUDE.md`), so
  there are no Behat jobs.
- **`gitleaks/gitleaks-action`**: needs a paid license key for repositories owned by an
  organization; the binary does not.

## Consequences

- A PR can't be merged red once `main` has branch protection with the CI jobs as required checks
  (set in the repository settings, not in code).
- Forgetting `make types` or committing a stale route tree fails CI instead of surfacing later as a
  confusing type error.
- Version bumps of PHP, Node or PostgreSQL have to touch `.github/workflows/ci.yaml` as well as the
  Dockerfile and `compose.yaml`.
- Deleting a test or adding a suppression needs a visible, labelled decision on the PR.
