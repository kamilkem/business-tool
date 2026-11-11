# 0008. Two frontend apps (admin panel and time clock PWA) in one pnpm workspace with shared packages

Status: Accepted

Date: 2026-11-10

## Context

Owners and managers work in a back office on a laptop: settings, invoices, revenue, payroll.
Employees need one thing, on their phone, at the start and end of a shift: clocking in and out. The
two audiences differ in device, permissions and size of the UI, but share the API, the session and
the visual language.

## Decision

1. Two applications: `frontend/admin` (the back office) and `frontend/timeclock` (mobile-first,
   installable as a PWA with `vite-plugin-pwa`). Both use the same stack
   (ADR [0003](0003-technology-stack.md)).
2. All JavaScript lives in one pnpm workspace in `frontend/`, with shared packages in
   `frontend/packages/`:
   - `@business-tool/ui`: shadcn/ui components, theme and hooks. New components are added with
     `pnpm dlx shadcn@latest add <name> -c admin` and land in `packages/ui/src/components`.
   - `@business-tool/api-client`: the generated API client and the shared session
     (ADR [0006](0006-plain-json-and-generated-typescript-client.md),
     ADR [0007](0007-stateless-jwt-authentication.md)).
3. Each app keeps its own session storage key, so signing in to one does not sign in to the other.

## Alternatives considered

- **One app with a responsive employee section.** A single deployment, but the employee bundle would
  ship the whole back office, the PWA scope and offline behavior would cover screens employees never
  use, and route-level permission checks would carry the separation alone.
- **Separate repositories per app.** Shared UI and client code would need publishing and versioning.

## Consequences

- Each app can be built, deployed and cached independently, and the time clock stays small.
- A change to a shared package can break both apps; `make qa` typechecks, lints and builds the whole
  workspace.
- A third app (e.g. for accountants) would follow the same pattern.
