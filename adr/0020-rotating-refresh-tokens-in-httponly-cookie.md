# 0020. Sessions outlast a shift: rotating refresh tokens in an HttpOnly cookie, revoked on sign-out and password change

Status: Accepted

Date: 2026-11-12

## Context

JWTs live 1 hour and there are no refresh tokens (ADR [0007](0007-stateless-jwt-authentication.md)
point 4). Employees clock in and out on their own phones
(ADR [0016](0016-clock-in-on-own-phone-server-time.md)), so clocking out after a normal shift
usually starts with signing in again, and managers in the admin panel are sent to the sign-in page
every hour. Setting or resetting a password does not end existing sessions either
(ADR [0013](0013-member-access-by-one-time-email-link.md)), because a JWT cannot be revoked.

Constraints:

- Users are global, organizations are tenants (ADR [0001](0001-multi-tenancy-shared-database-organization-filter.md)).
  Access to an organization is checked on every request from the active membership
  (ADR [0014](0014-deactivate-members-never-delete.md)), so a session does not need to know about
  organizations.
- There are two SPAs (ADR [0008](0008-two-frontend-apps-in-one-pnpm-workspace.md)) on separate
  origins. Both call the API with relative `/api/...` URLs: the Vite dev server proxies them, and the
  hosting spike (#35) plans to serve `/api` from each app's own domain in production too.
- The JWT is kept in `localStorage` (ADR 0007 point 3), so any script on the app's origin can read
  it. A credential that lives for weeks should not be readable that way.
- The code follows the module layering of ADR [0004](0004-layered-modules-and-cqrs-buses.md), and
  time comes from the clock passed into the Domain (ADR [0015](0015-time-from-symfony-clock-passed-into-domain.md)).

## Decision

1. **A refresh token is issued at every sign-in and kept in an `HttpOnly` cookie.** A successful
   `POST /api/auth/login` returns the JWT in the body as before and sets the cookie `refresh_token`
   with `HttpOnly; Secure; SameSite=Strict; Path=/api/auth`. JavaScript never sees the refresh
   token. Because `/api` is served from each app's own origin, each app has its own cookie and its
   own session: signing out of the admin panel does not sign the person out of the time clock.
2. **The JWT stays in `localStorage` and lives 15 minutes** (was 1 hour; `JWT_TOKEN_TTL` in
   seconds, so dev can shorten it). The shorter lifetime bounds how long a JWT outlives a revoked
   session.
3. **`POST /api/auth/refresh` exchanges the cookie for a new JWT and a new refresh token.** It needs
   no `Authorization` header and answers like the login: `200 { token }` with a new cookie, or `401`
   with the cookie cleared. It is a firewall authenticator next to `json_login`, so login and refresh
   share Lexik's success handler.
4. **Lifetime: sliding 30 days, at most 90 days from the sign-in.** Each refresh token expires 30
   days after it was issued, but never later than 90 days after the sign-in that started its
   session. Someone who opens the time clock at least once a month stays signed in; everyone signs
   in again at least once a quarter.
5. **Every refresh rotates the token, and reusing a rotated one revokes the session.** All tokens
   issued from one sign-in share a session (family) id. Refreshing marks the presented token as
   rotated and issues its successor in the same session. Presenting a rotated token again within
   **30 seconds** is treated as a retry (a lost response, two tabs refreshing at once) and gets
   another successor; presenting it later is treated as theft and revokes every token of that
   session, so both the thief and the person must sign in again.
6. **Revocation:**
   - **Sign-out** (`POST /api/auth/logout`, public, `204`) revokes the session of the presented
     cookie and clears it. The apps call it before clearing their local session.
   - **Setting or resetting a password** (ADR 0013, and changing one's own password in #12) revokes
     all of the user's sessions, on every device and in both apps.
   - **Deactivation** (ADR 0014) is per organization while sessions are per user, so deactivating
     a member revokes nothing as long as they are still an active member of another organization:
     the membership check already shuts them out of the organization on the next request. When the
     deactivation leaves the user with no active membership anywhere, all their sessions are revoked,
     as their pending invitation is (ADR 0014 point 6).
   - A JWT issued before a revocation stays valid until it expires (at most 15 minutes).
7. **An own `RefreshToken` aggregate in `App\User`, no new library.** It is global, not tenant-aware,
   like `PasswordToken`. It stores the user, the session id, when the session started, the SHA-256
   hash of a 32-byte random secret (base64url in the cookie; the same secret format as ADR 0013), when
   it was issued, when it expires, and when it was rotated or revoked. The rules (validity, the grace
   period, the 30/90-day lifetime) live in the aggregate; issuing, rotating and revoking are commands
   with handlers; the Ui layer generates the new secret (as it generates ids, ADR
   [0009](0009-uuid-v7-identifiers-assigned-by-caller.md)) so it can set the cookie.
8. **The shared `@business-tool/api-client` refreshes silently.** On a `401` for a request that
   carried a JWT, the client refreshes once and retries the request with the new JWT. Concurrent
   `401`s share one refresh. When the refresh fails, the client clears the session and calls
   `onUnauthorized` (the sign-in page, ADR 0016's `?redirect=`), as a `401` did before.

This supersedes point 4 of ADR 0007 ("no refresh tokens yet"). Its other points stand: the API is
stateless for every request except refresh and sign-out, the JWT identifies the user only, and the
JWT and the selected organization are kept in `localStorage`.

## Alternatives considered

- **The same own aggregate, with the refresh token in `localStorage`** and in the request and
  response bodies. Works with any hosting layout, including an API on another site, but an XSS bug
  would leak a credential valid for up to 90 days instead of a JWT valid for 15 minutes; reuse
  detection only limits the damage once the person refreshes.
- **`gesdinet/jwt-refresh-token-bundle`** (3.1 supports Symfony 8.1 and Lexik 3, with single-use
  tokens and a cookie option). Less code, but it stores tokens in plain text keyed by username rather
  than a hash keyed by user id (unlike ADR 0013), has no token families or reuse detection, brings its
  own entity and authenticator outside the Domain / Application layers (ADR 0004), and reads the
  system time itself instead of the clock (ADR 0015), so tests cannot freeze it with `mockTime()`.
- **A longer JWT lifetime** (e.g. 12 hours). No new code, but a stolen JWT would stay valid that long
  and still nothing could be revoked; ADR 0016 already rejected it.
- **JWT in an `HttpOnly` cookie as well** (ADR 0007's alternative). Removes the remaining XSS
  exposure, but every API request would then depend on a cookie and need CSRF protection. Not needed
  to make a session outlast a shift; it can follow in its own ADR.

## Consequences

- **Hosting constraint:** the API must be reachable under each app's own origin at `/api` (a reverse
  proxy per app domain), or at least on the same site. An API on another site would not receive a
  `SameSite=Strict` cookie, and browsers increasingly block third-party cookies anyway. #35 must keep
  this layout; a different one needs a new ADR.
- **CSRF:** `SameSite=Strict` keeps the cookie off cross-site requests, the cookie is sent only to
  `/api/auth/*`, and the refresh response (the JWT) cannot be read cross-origin without CORS
  credentials, which are not enabled.
- **State on the server:** refresh and sign-out read and write a `refresh_token` table. Every other
  request stays stateless. Rotated, revoked and expired tokens stay in the table (hashes only) until
  the clean-up job (#22) removes them.
- **Revocation now exists,** at the granularity of a session: a password reset ends every session
  within 15 minutes (the remaining JWT lifetime), and a person who lost access everywhere cannot keep
  refreshing.
- **A refresh can sign someone out unexpectedly** if a response is lost and the retry comes more than
  30 seconds later; they sign in again. Accepted for the simpler rule.
- **Two independent sessions per person** (admin and time clock), each signed out separately. "Sign
  out everywhere" is not offered; a password reset does it.
- **Local development:** `Secure` cookies work on `http://localhost` in current browsers, so no
  HTTPS is needed. Both apps run on `localhost`, where cookies ignore the port, so the Vite dev proxy
  (`frontend/packages/api-client/vite-api-proxy.ts`) stores each app's cookie under its own name;
  otherwise signing in to one app would replace the other app's session.
