# 0021. The admin is for owners and managers of the selected organization; employees use the time clock

Status: Accepted

Date: 2026-11-12

## Context

ADR [0008](0008-two-frontend-apps-in-one-pnpm-workspace.md) split the frontend into the admin
(back office) and the time clock (employees' phones), but did not say who may use the admin. Until
#15 an employee who signed in to it got a sidebar of pages that each answered "only owners and
managers": Team, Time tracking and Shift requests checked the role page by page, and Organization
settings showed a read-only form. Everything an employee can do through the API (their own shifts,
clocking in and out, their own change requests, their account and password) is in the time clock.

Roles are per organization (ADR [0001](0001-multi-tenancy-shared-database-organization-filter.md)):
the same person can be an owner of one organization and an employee of another, and anyone may create
an organization of their own. The admin selects one organization at a time (`X-Organization-Id`).

## Decision

1. **The admin's business screens are for owners and managers of the selected organization.** The
   dashboard, Team, Time tracking, Shift requests, Organization settings and every later module need
   the role `owner` or `manager` in the organization selected in the admin (from `/me`'s
   memberships). Access is never decided per user.
2. **An employee of the selected organization, or someone with no organization, gets a limited
   frame:** no sidebar; a start page that sends them to the time clock (a link to
   `VITE_TIMECLOCK_URL`) and offers a switch to each organization they own or manage; the
   organization switcher; the Account page; sign-out. The Account page is open to every role.
   Someone with no organization is told to wait for an invitation.
3. **The admin does not offer to create an organization, for now.** The screen that let a signed-in
   user without an organization create one is removed, and the limited frame does not offer it either.
   The API endpoint (`POST /api/organizations`) is unchanged.
4. **One helper decides, in `frontend/admin/src/lib/access.ts`:** `can(role, capability)` for
   navigation and dashboard cards, and `requireCapability(capability)` as each business route's
   `beforeLoad`. A role without the capability is redirected to `/` (the dashboard, or the limited
   start page), so a typed URL never shows a page or calls an endpoint the API would refuse. Per-page
   role checks are not written any more.
5. **This is UX only.** The API stays the source of truth and answers 403 on its own; the finer rules
   on members (`assignableRoles`, `canActOn` in `lib/members.ts`) mirror the domain and stay where
   they are.

## Alternatives considered

- **A minimal admin for employees (Dashboard and Account only).** The time clock already has the
  account screen and everything else an employee does; the admin would keep an app with nothing in it
  for them.
- **Switch an employee automatically to an organization they manage.** Surprising, and it hides
  which organization is selected; the start page offers the switch instead.
- **Keep a "only owners and managers" card on each page.** Scattered checks that drift apart, and
  each page's loader had to skip its API calls by hand.

## Consequences

- A new admin page adds a capability in `access.ts` and `beforeLoad: requireCapability(...)`; its
  sidebar link and dashboard card use `can()` with the same capability.
- Today every capability means owner or manager. An owner-only capability would redirect a manager
  to the dashboard without a word; that is the moment to add a 403 screen instead.
- There is no self-service sign-up in the admin: until it comes back, a new organization is created
  outside the admin. Offering it again (for example in the limited frame, where an employee could
  start their own business) is a later decision, together with whether `POST /api/organizations`
  stays open to every signed-in user.
- The admin needs the time clock's address at build time (`VITE_TIMECLOCK_URL`; the local value is
  in `frontend/admin/.env`). Hosting (#35 onward) must set it for each environment.
