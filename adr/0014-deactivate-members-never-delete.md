# 0014. Members are deactivated, never deleted; a deactivated membership gives no access

Status: Accepted

Date: 2026-11-11

## Context

Owners and managers must be able to remove someone who left (#11; the admin Team page, #14, needs it).
The person's history stays behind: shifts and paychecks (the time clock and payroll modules, not built
yet) will refer to the person in the organization, and a payslip from last year must still show who
worked those hours. Until now a `Membership` was either there or not, and every access check asked
"is there a membership?" (ADR [0001](0001-multi-tenancy-shared-database-organization-filter.md)). A
signed-in person carries a stateless JWT that does not list organizations
(ADR [0007](0007-stateless-jwt-authentication.md)), so removing access cannot rely on revoking tokens.
Invited people who have not set a password yet hold a one-time link
(ADR [0013](0013-member-access-by-one-time-email-link.md)); `PasswordToken`s belong to the global user,
not to an organization.

## Decision

1. **A membership is deactivated, never deleted.** `Membership` gets a nullable `deactivatedAt`
   (UTC). It is set by `Organization::deactivateMember()` and cleared by
   `Organization::reactivateMember()`, which brings the member back with the role they had. Both are
   idempotent.
2. **The rules live in the aggregate.** Owners deactivate and reactivate anyone, themselves included;
   managers employees only (the roles they may give, as for every other action on a member). The
   organization always keeps at least one *active* owner: deactivating or demoting the last one is
   refused (`LastOwnerException`, 409).
3. **A deactivated membership gives no access anywhere.**
   - `SelectOrganizationFromHeaderListener` answers 403 for it, as for a non-member, so a JWT issued
     before the deactivation stops working for that organization on the next request.
   - Actor checks in the domain and in handlers use `Organization::isActiveMember()`, not
     `membershipOf()` (which still returns deactivated memberships, for history).
   - `GET /me` and `GET /organizations` leave the organization out.
4. **Deactivated members stay visible to the team managers on request.** `GET /members` lists active
   members; `?includeDeactivated=true` lists everyone, and `Member.deactivatedAt` says who is
   deactivated.
5. **A deactivated member must be reactivated explicitly.** Adding them again with `POST /members`,
   changing their role or resending their invitation is a 409 (`MemberDeactivatedException`).
6. **A pending invitation stops working.** On `MemberDeactivated` (a domain event), `App\User`
   invalidates the unused tokens of a person without a password, unless they are still an active
   member of another organization, whose invitation the link may be for. Reactivation does not resend
   the invitation; the owner or manager resends it.
7. **Endpoints:** `POST /members/{userId}/deactivate` and `POST /members/{userId}/reactivate`, both
   204 with no body.

## Alternatives considered

- **Delete the membership row.** Simple, but the history that refers to the person would lose its
  link, or block the deletion with a foreign key.
- **`DELETE /members/{userId}`.** Fits REST, but reads like a hard delete although the row stays, and
  has no natural counterpart for reactivation.
- **`POST /members` silently reactivates a deactivated member.** One endpoint fewer for the client,
  but a typo in a role or an unintended re-add would quietly restore access; an explicit reactivate
  keeps that decision visible.

## Consequences

- Every new access check must use the active membership (`isActiveMember()`, the listener); using
  `membershipOf()` for access would let a deactivated member through. CLAUDE.md states the rule.
- Future business entities (shifts, paychecks) can refer to the person without caring whether they
  still work there.
- Removal takes effect on the next request without token revocation, because membership is checked
  per request; the JWT itself stays valid for the person's other organizations and for `/me`.
- An invitation is per user, so deactivating someone invited to two organizations keeps their link
  alive for the other one.
- The membership table keeps growing with people who left; nothing purges them (personal-data
  retention is a separate decision).
