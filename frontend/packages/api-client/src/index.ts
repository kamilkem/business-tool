import createClient, { type Middleware } from "openapi-fetch"

import { createTokenRefresher } from "./refresh"
import type { components, paths } from "./schema"
import type { Session } from "./session"

export * from "./link-token"
export * from "./refresh"
export * from "./session"
export * from "./sign-in-redirect"
export type { components, paths }

export const ORGANIZATION_HEADER = "X-Organization-Id"

/**
 * User-level endpoints work across organizations, so they never get the
 * organization header. This also lets the app recover when the selected
 * organization is stale (deleted, or the user was removed from it): /me
 * still loads, and the app selects a valid organization from it.
 */
const USER_SCOPED_PATHS = [/^\/api\/me(\/|$)/, /^\/api\/organizations(\/|$)/]

/**
 * Public endpoints (sign-in, refresh, sign-out, set a password from a link,
 * request a reset) get neither header: a stale token would fail
 * authentication there, and the organization header would ask for a
 * membership check. A 401 from them is never refreshed.
 */
const PUBLIC_PATHS = [/^\/api\/auth\//]

export interface ApiClientOptions {
  /** API origin; empty string when the dev server proxies /api. */
  baseUrl?: string
  session: Session
  /**
   * Called when the person must sign in again (the refresh token was refused,
   * or the retried request still got 401), after the session is cleared.
   */
  onUnauthorized?: () => void
  /** For tests; defaults to the global fetch. */
  fetch?: typeof globalThis.fetch
}

export class ApiError extends Error {
  readonly status: number
  readonly body: unknown
  /** Seconds from the `Retry-After` header (429), when the API sent one. */
  readonly retryAfter: number | null

  constructor(status: number, body: unknown, headers?: Headers) {
    const detail =
      typeof body === "object" && body !== null && "detail" in body
        ? String((body as { detail: unknown }).detail)
        : `Request failed with status ${status}`
    super(detail)
    this.status = status
    this.body = body
    const retryAfter = Number.parseInt(headers?.get("Retry-After") ?? "", 10)
    this.retryAfter = Number.isFinite(retryAfter) && retryAfter >= 0 ? retryAfter : null
  }
}

/** 422 violations from API Platform, keyed by property path (the first message per path). */
export function violationsOf(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError) || error.status !== 422) return {}
  const body = error.body as components["schemas"]["ConstraintViolation"] | null
  const result: Record<string, string> = {}
  for (const violation of body?.violations ?? []) {
    result[violation.propertyPath] ??= violation.message
  }
  return result
}

/**
 * Typed client for the Business Tool API (types generated from OpenAPI).
 *
 * A 401 on a request that carried a JWT refreshes it once (ADR 0020) and
 * retries the request with the new token; concurrent 401s share one refresh.
 * When the refresh is refused, the session is cleared and `onUnauthorized`
 * runs. When it cannot reach the API, the original 401 is returned and the
 * session is kept.
 */
export function createApiClient({ baseUrl = "", session, onUnauthorized, fetch = globalThis.fetch }: ApiClientOptions) {
  // Plain JSON (not JSON-LD) responses; requests send Accept: application/json.
  const client = createClient<paths, "application/json">({
    baseUrl,
    headers: { Accept: "application/json" },
    fetch: (request) => fetch(request),
  })
  const refresh = createTokenRefresher({ baseUrl, session, fetch })
  // A copy of each authenticated request, for the retry: a sent body cannot be read again.
  const retryable = new Map<string, Request>()

  function signInAgain() {
    session.signOut()
    onUnauthorized?.()
  }

  async function retry(original: Request, token: string): Promise<Response> {
    const request = new Request(original)
    request.headers.set("Authorization", `Bearer ${token}`)
    const response = await fetch(request)
    if (response.status === 401) signInAgain()
    return response
  }

  const auth: Middleware = {
    onRequest({ request, id }) {
      const path = new URL(request.url, "http://localhost").pathname
      if (PUBLIC_PATHS.some((pattern) => pattern.test(path))) return request
      const { token, organizationId } = session.get()
      if (token) request.headers.set("Authorization", `Bearer ${token}`)
      if (organizationId && !USER_SCOPED_PATHS.some((pattern) => pattern.test(path))) {
        request.headers.set(ORGANIZATION_HEADER, organizationId)
      }
      // Cloned with every header set, so the retry selects the same organization.
      if (token) retryable.set(id, request.clone())
      return request
    },
    async onResponse({ response, id }) {
      const original = retryable.get(id)
      retryable.delete(id)
      if (response.status !== 401 || !original) return response

      // Another request refreshed while this one was on its way: retry with that token.
      const current = session.get().token
      if (current && original.headers.get("Authorization") !== `Bearer ${current}`) {
        return retry(original, current)
      }

      const outcome = await refresh()
      if (outcome.status === "refreshed") return retry(original, outcome.token)
      if (outcome.status === "rejected") signInAgain()
      return response
    },
    onError({ id }) {
      retryable.delete(id)
    },
  }
  client.use(auth)

  return client
}

export type ApiClient = ReturnType<typeof createApiClient>

/** Unwraps an openapi-fetch result, throwing ApiError on failure. */
export async function unwrap<T>(
  promise: Promise<{ data?: T; error?: unknown; response: Response }>,
): Promise<T> {
  const { data, error, response } = await promise
  if (error !== undefined || !response.ok) throw new ApiError(response.status, error, response.headers)
  return data as T
}

/**
 * Exchanges credentials for a JWT; the response also sets the refresh token
 * cookie (ADR 0020). The auth routes are not in the OpenAPI spec.
 */
export async function login(email: string, password: string, baseUrl = ""): Promise<string> {
  const response = await fetch(`${baseUrl}/api/auth/login`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ email, password }),
  })
  const body: unknown = await response.json().catch(() => null)
  if (!response.ok) {
    const message =
      typeof body === "object" && body !== null && "message" in body
        ? String((body as { message: unknown }).message)
        : "Login failed"
    throw new ApiError(response.status, { detail: message })
  }
  return (body as { token: string }).token
}
