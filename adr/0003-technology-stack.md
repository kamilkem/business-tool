# 0003. Technology stack: Symfony + API Platform on PostgreSQL, React SPAs

Status: Accepted

Date: 2026-11-10

## Context

The product is a back office (forms, lists, reports) with a typed REST API and two clients: an admin
panel and a phone-first time clock for employees. It has to be built and maintained by a small team,
run cheaply, and be easy to start locally.

## Decision

| Area | Choice |
|---|---|
| API | PHP 8.4, Symfony 8.1, API Platform 5, Doctrine ORM 3, Symfony Messenger (buses only), LexikJWTAuthenticationBundle |
| Database | PostgreSQL 17 |
| Runtime | FrankenPHP in Docker; `compose.yaml` runs the API and the database |
| Backend quality | PHPUnit, Zenstruck Foundry, PHPStan level 8, Deptrac |
| Frontend | React 19, TypeScript, Vite, TanStack Router (file routes) and TanStack Query, shadcn/ui on Tailwind 4 |
| Frontend tooling | pnpm workspace in `frontend/`, Node 22+ |

API Platform generates the OpenAPI document that the TypeScript client is built from
(ADR [0006](0006-plain-json-and-generated-typescript-client.md)).

## Consequences

- One `make setup` brings up the whole stack locally; only Docker, Node and pnpm are needed on the
  host.
- PostgreSQL-specific features (JSONB, partial indexes, `uuid` type) are allowed; portability to
  another database is not a goal.
- The frontend has no server-side rendering. Both apps are static builds that talk to the API from
  the browser, which suits a signed-in back office that search engines never see.
- Upgrading a major version of any of these is a decision of its own and gets an ADR when it changes
  conventions.
