# 0006. Clients use plain JSON and a TypeScript client generated from OpenAPI

Status: Accepted

Date: 2026-11-10

## Context

API Platform defaults to JSON-LD/Hydra. Both frontend apps are written in TypeScript and should not
duplicate API types by hand: a renamed field should fail the frontend build, not production.

## Decision

1. The frontend requests `application/json`. JSON-LD stays enabled on the API (for the docs and any
   future consumer) but the apps do not use Hydra metadata.
2. `frontend/packages/api-client` exports an `openapi-fetch` client typed as
   `createClient<paths, "application/json">`. `src/schema.d.ts` is generated from the API's OpenAPI
   document by `make types` and is never edited by hand.
3. Types are used by their schema names: `components["schemas"]["<shortName>"]` for a resource,
   `"<shortName>.<InputClass>"` for an input, `".jsonMergePatch"` for a merge-patch input.
4. Data fetching goes through `unwrap(api.GET(...))` inside TanStack Query `queryOptions`.
5. After changing an API resource: `make types`, then fix the TypeScript errors in the same change.

## Alternatives considered

- **Hydra/JSON-LD in the clients.** Adds `@id`/`@type`/`hydra:member` to every type and needs a
  Hydra-aware client for collections and errors, for no benefit in two first-party apps.
- **Hand-written API types or a generated SDK with classes.** Hand-written types drift; heavier
  generators add runtime code where `openapi-fetch` adds types only.

## Consequences

- API changes break the frontend at compile time (`pnpm typecheck`, part of `make qa`).
- The generated schema is committed, so a stale `schema.d.ts` shows up in review as a missing diff.
- `make types` needs the API running, since it reads the live OpenAPI document.
