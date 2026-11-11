# 0013. New members get access through a one-time e-mailed link; the same tokens reset forgotten passwords

Status: Accepted

Date: 2026-11-11

## Context

Owners and managers add the people who work for them (#8) before anyone can clock in, so every
employee of the first bar goes through this flow before go-live (#39). Users are global
(ADR [0001](0001-multi-tenancy-shared-database-organization-filter.md)): the e-mail of a new member
may already belong to someone who works for another organization. Signing in is a JWT issued by
`POST /api/auth/login` for an e-mail and password (ADR [0007](0007-stateless-jwt-authentication.md));
today nothing lets one person give another a password, and nothing lets a person recover a forgotten
one (#13). E-mail is now available: queued in PostgreSQL and sent by a worker
(ADR [0012](0012-transactional-email-smtp-async-queue.md)).

Whatever we choose adds the first unauthenticated endpoints that change an account, next to login.
Staff turnover in hospitality is high, so the flow runs often and is done by managers, not by us.

## Decision

1. **A new person gets an invitation e-mail with a one-time link to set their own password.**
   Adding a member whose e-mail has no user creates the `User` without a password, adds the
   membership at once (`Organization::addMember()`, so the member is listed and has a role
   immediately) and, after both are saved, queues the invitation. Nobody but the invited person
   chooses or sees the password, and the manager never sees the link.
2. **An existing user added to another organization gets only a membership and a notification
   e-mail** ("you were added to …", with a link to sign in). No token is issued and the password does
   not change. A user who was invited earlier but has not set a password yet counts as new: adding
   them again (to any organization) or resending sends a fresh invitation and invalidates the old
   link.
3. **One token mechanism for invitations and password resets, owned by `App\User`.** It sets a
   password for a global user, so it is not tenant-aware and does not belong to `App\Organization`.
   A `PasswordToken` aggregate records the user, a purpose (`invitation` or `password_reset`), the
   hash of the secret, an expiry and when it was used. Rules:
   - The secret is 32 bytes from `random_bytes()`, base64url-encoded. It appears only in the e-mail;
     the database stores its SHA-256 hash (unique, used for lookup). A fast hash is enough because
     the secret is random, not chosen by a person.
   - An invitation is valid for **7 days**, a password reset for **1 hour**.
   - A token works **once**. Issuing a token invalidates the user's unused tokens of the same
     purpose, and setting a password by any route (a token, or changing one's own password in #12)
     invalidates all of the user's unused tokens.
   - The organization (its name, who invited) goes into the e-mail only, not into the token. Access
     to an organization still comes from the membership, checked on every request (ADR 0001).
4. **Two public endpoints, both rate-limited** with `symfony/rate-limiter` (per client IP, and per
   e-mail for the reset request):
   - Set a password: `POST` with `{ token, password }` in the body (never in the path or query, so it
     stays out of access logs) returns `204`. An unknown, used or expired token gets one and the same
     `404` response, so the endpoint tells nothing about which one it was.
   - Request a password reset (#13): `POST` with `{ email, app }` always returns the same success
     response, whether or not the e-mail has an account.
5. **Setting a password does not sign the person in.** The endpoint returns `204` and the page sends
   them to the sign-in form. `POST /api/auth/login` stays the only place that issues JWTs, and
   ADR 0007 is unchanged.
6. **Links open a page in one of our frontend apps, built from configured base URLs** (`ADMIN_URL`,
   `TIMECLOCK_URL`), never from the request's `Host` header. An invitation for an employee opens the
   time clock, one for an owner or manager the admin panel; a reset opens the app named in the
   request (`app` is the enum `admin` or `timeclock`, not a URL). The secret travels in the URL
   fragment (`…/set-password#token=…`), which browsers do not send to the server that hosts the app
   or in a `Referer`; the page reads it and posts it to the API.

## Alternatives considered

- **An initial password set by the manager**, changed at first sign-in. Works for staff without an
  e-mail address, but the manager knows the password and can sign in as the employee (and clock in
  for them, which undermines time tracking and payroll), the password is passed on over chat or
  paper, and "must change password" needs a flag that blocks the whole API until it is done.
- **E-mail link plus a "copy invitation link" button** in the admin panel, for staff who would
  rather get it by text message. Whoever holds the link sets the password, so it has the same
  weakness as an initial password. Not now; it can be added later without changing the tokens.
- **Signing in straight after setting the password** (the endpoint returns a JWT). One step fewer
  for the person, but it makes the set-password endpoint a second place that issues JWTs next to
  login.
- **An `Invitation` in `App\Organization`, with the membership created only on acceptance.** It
  would need a second mechanism for password resets, and until acceptance the person would be
  missing from the member list and could not be given a role or shifts.
- **Stateless signed links** (an HMAC over the user, purpose and expiry, no table). Nothing to store,
  but a link cannot be revoked when a new one is sent, and single use would have to be derived from
  the password hash.
- **`symfonycasts/reset-password-bundle`.** Covers resets only, not invitations, and brings its own
  entity and controller shape that does not fit the Domain / Application / Ui layering
  (ADR [0004](0004-layered-modules-and-cqrs-buses.md)).

## Consequences

- **New public surface.** The two endpoints sit next to login under `PUBLIC_ACCESS`. Their tests must
  cover unknown, used and expired tokens with one indistinguishable response, the identical reset
  response for known and unknown e-mails, and the rate limits.
- **A user can exist without a password.** Sign-in fails for them like a wrong password. The member
  list (#7, #14) can show "invitation pending" from that, and the manager can resend.
- **Every member needs a working e-mail address.** Someone without one cannot join until the
  copy-link or initial-password alternative is revisited in a new ADR.
- **The person adding a member can tell whether the e-mail already had an account**: an existing
  user keeps their own name (the names typed by the manager are ignored), and gets a notification
  instead of an invitation. Accepted: only owners and managers add members, and the person is about
  to share the organization with them anyway.
- **Valid links sit in plain text in the mail queue** (`messenger_messages`) until the worker sends
  them (ADR 0012). A database dump taken in that window exposes them; the short lifetimes bound
  that.
- **Changing or resetting a password does not end existing sessions.** JWTs cannot be revoked before
  they expire (ADR 0007), so a reset after a stolen token protects the account only once that token
  expires. Revocation belongs to a future ADR that replaces 0007.
- **Expired and used tokens stay in the table** until a clean-up job exists (#22). They hold only
  hashes, so they are harmless meanwhile.
- **New configuration:** `ADMIN_URL` and `TIMECLOCK_URL` (local values in `api/.env`, production
  values with hosting, #35), and the `symfony/rate-limiter` package.
- **Both apps need a "set your password" page** that reads the token from the fragment, and a
  "forgot password" form for #13 (#70).
