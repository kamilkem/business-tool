# 0007. Stateless JWT authentication, token kept in the browser's localStorage

Status: Accepted (point 4 superseded by [0020](0020-rotating-refresh-tokens-in-httponly-cookie.md))

Date: 2026-11-10

## Context

The API serves two browser apps (admin panel and time clock) that are built and possibly hosted
separately from the API. Every request also carries the selected organization
(ADR [0001](0001-multi-tenancy-shared-database-organization-filter.md)), so the API needs a way to
identify the user that works across origins and does not depend on server-side session state.

## Decision

1. The `main` firewall is stateless. `POST /api/auth/login` (`json_login` with `email` and
   `password`) returns a JWT issued by LexikJWTAuthenticationBundle; every other `/api` route except
   the docs requires `Authorization: Bearer <token>`.
2. The token identifies the user only. The organization is chosen per request with
   `X-Organization-Id`, not encoded in the token.
3. `frontend/packages/api-client` provides one session implementation (`createSession()`) shared by
   both apps: the token and the selected organization are kept in `localStorage` under an
   app-specific key and exposed to React through `useSyncExternalStore`.
4. There are no refresh tokens yet: when the token expires, the user signs in again.

## Alternatives considered

- **Session cookie with a server-side session.** Requires sticky or shared session storage, CSRF
  protection and same-site or CORS-with-credentials setup between the apps and the API.
- **JWT in an `HttpOnly` cookie.** Keeps the token out of reach of JavaScript, but brings back the
  cookie, CSRF and cross-origin concerns above. Worth revisiting before handling real payroll data at
  scale.

## Consequences

- The API stays stateless and horizontally scalable; CORS is configured with `nelmio/cors-bundle`.
- A token in `localStorage` can be read by any script running on the app's origin, so an XSS bug
  would expose it. The apps must not render untrusted HTML, and third-party scripts on these origins
  need a decision of their own.
- A token cannot be revoked before it expires. Removing a member takes effect on their next request
  anyway, because membership is checked per request, but a stolen token stays valid for the user's
  other organizations until expiry.
- Moving to refresh tokens or cookies later is a new ADR that supersedes this one.
