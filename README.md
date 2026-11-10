# Business Tool

Back office for small hospitality businesses (bars, cafés, shops): expenses from KSeF invoices, manually entered income, time tracking and payroll. Built for one bar first, designed for many businesses (multi-tenant).

| App | Path | Stack | Dev URL |
| --- | --- | --- | --- |
| API | `api/` | PHP 8.4, Symfony 8.1, API Platform 5, PostgreSQL 17, FrankenPHP | http://localhost:8000/api |
| Admin panel | `frontend/admin/` | React 19, Vite, TanStack Router + Query, shadcn/ui, Tailwind 4 | http://localhost:5173 |
| Timeclock (PWA) | `frontend/timeclock/` | same as admin, plus vite-plugin-pwa | http://localhost:5174 |

All JavaScript lives in `frontend/`, a pnpm workspace. Its shared packages are in `frontend/packages/`:

- `@business-tool/ui`: shadcn/ui components, theme (`globals.css`) and hooks used by both apps.
- `@business-tool/api-client`: typed API client generated from the OpenAPI spec, plus the shared session (JWT and selected organization).

## Getting started

Requirements: Docker, Node 22+, pnpm 9+.

```bash
make setup        # start containers, install deps, create DB with demo data, generate API types
make admin        # admin panel on :5173
make timeclock    # timeclock on :5174
```

Demo accounts (password `password`): `owner@example.com`, `manager@example.com`, `employee@example.com`, all members of "Demo Bar".

Run `make help` to see all tasks. The most common ones:

```bash
make test         # PHPUnit
make qa           # schema check, Deptrac layers, PHPStan, tests, TS typecheck, lint, builds
make migration    # generate a Doctrine migration after changing entities
make types        # regenerate the TS client after changing the API
```

## Modules

| Module | Status |
| --- | --- |
| Core: organizations, users, memberships, JWT auth, tenant isolation | Done |
| Expenses: KSeF invoice import and categorization | Planned |
| Income: manual daily revenue entry | Planned |
| Time tracking: clock in/out from the timeclock app | Planned |
| Payroll: paychecks from logged time | Planned |

See [CLAUDE.md](CLAUDE.md) for the architecture and conventions.
