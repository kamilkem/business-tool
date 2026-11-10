# 0001. Multi-tenancy: one shared database, `organization_id` on every business row, enforced by a Doctrine filter

Status: Accepted

Date: 2026-11-10

## Context

The first user is a single bar, but the product is meant for many small hospitality businesses, and
one person (an owner, a manager running two venues) can belong to more than one of them. Retrofitting
tenancy after business data exists is expensive and error-prone, so it has to be in place before the
first business module (expenses, income, time tracking, payroll). A leak of one business's invoices
or payroll to another is the worst failure the product can have.

## Decision

1. **The organization is the tenant.** `Organization` (module `App\Organization`) is the tenant
   aggregate. `User` (module `App\User`) is global. `Membership` links a user to an organization with
   a role: `owner`, `manager` or `employee`.
2. **One database, one schema, row-level tenancy.** Every business entity implements
   `TenantAwareInterface` and uses `TenantAwareTrait`, which maps an `organization` association.
3. **Isolation is enforced below the application code.** `TenantFilter`, a Doctrine SQL filter that
   is always enabled, adds `organization_id = :current` to every query on a tenant-aware table.
   `TenantAssignListener` sets the organization on persist. Handlers and repositories do not filter
   by organization themselves.
4. **Secure by default.** With no organization selected, tenant-aware tables return no rows
   (`1 = 0`), instead of every row.
5. **The client selects the organization per request** with the `X-Organization-Id` header.
   `SelectOrganizationFromHeaderListener` checks membership (400 for a malformed id, 403 for a
   non-member) and calls `TenantContextInterface::switchTo()`. Console commands and message handlers
   call `switchTo()` themselves before touching tenant data.
6. **Existence does not leak.** A resource the user cannot see because they are not a member is
   reported as 404, not 403.
7. **Proof per endpoint.** `tests/Functional/Organization/TenantIsolationTest.php` covers the filter
   and the listener; every new tenant-aware endpoint adds an API test showing another organization's
   data is invisible.

## Alternatives considered

- **Single-tenant application for the bar.** Simplest now, but turning it into a multi-tenant product
  later would mean touching every table, query and test.
- **Schema per tenant or database per tenant.** Stronger physical isolation, but migrations, fixtures,
  connection handling and reporting multiply with every customer. Not justified for many small
  tenants with small data volumes.
- **Organization in the JWT claims** instead of a header. Switching organizations would require a new
  token, and the token would have to be reissued whenever memberships change. The header keeps the
  token about *who* the user is and the request about *which* organization it acts on.
- **Filtering in each repository by hand.** One forgotten `WHERE` leaks data; the filter makes the
  safe behavior the default.

## Consequences

- Every new business entity must implement `TenantAwareInterface`; one that does not is visible to
  every organization. This is the first thing to check when reviewing a new entity.
- Unique constraints on business data must include `organization_id`.
- Code that legitimately crosses tenants (an admin report, a migration) has to disable the filter
  explicitly and visibly.
- Background jobs carry an extra step (`switchTo()` per organization), and a job that forgets it sees
  nothing rather than everything.
- HTTP caches vary on `X-Organization-Id` (`api_platform.yaml`), and the frontend includes the
  organization in query keys or clears the cache on switch.
