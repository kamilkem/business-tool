import createClient, { type Middleware } from "openapi-fetch"

import type { components, paths } from "./schema"
import type { Session } from "./session"

export * from "./link-token"
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
const USER_SCOPED_PATHS = [/^\/api\/me$/, /^\/api\/organizations(\/|$)/]

/**
 * Public endpoints (set a password from a link, request a reset) get neither
 * header: a stale token would fail authentication there (a 401 that signs the
 * person out), and the organization header would ask for a membership check.
 */
const PUBLIC_PATHS = [/^\/api\/auth\//]

export interface ApiClientOptions {
  /** API origin; empty string when the dev server proxies /api. */
  baseUrl?: string
  session: Session
  /** Called on 401 (expired or invalid token), after the session is cleared. */
  onUnauthorized?: () => void
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

/** Typed client for the Business Tool API (types generated from OpenAPI). */
export function createApiClient({ baseUrl = "", session, onUnauthorized }: ApiClientOptions) {
  // Plain JSON (not JSON-LD) responses; requests send Accept: application/json.
  const client = createClient<paths, "application/json">({
    baseUrl,
    headers: { Accept: "application/json" },
  })

  const auth: Middleware = {
    onRequest({ request }) {
      const path = new URL(request.url, "http://localhost").pathname
      if (PUBLIC_PATHS.some((pattern) => pattern.test(path))) return request
      const { token, organizationId } = session.get()
      if (token) request.headers.set("Authorization", `Bearer ${token}`)
      if (organizationId && !USER_SCOPED_PATHS.some((pattern) => pattern.test(path))) {
        request.headers.set(ORGANIZATION_HEADER, organizationId)
      }
      return request
    },
    onResponse({ response }) {
      if (response.status === 401 && session.get().token) {
        session.signOut()
        onUnauthorized?.()
      }
      return response
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

/** Exchanges credentials for a JWT. The login route is not in the OpenAPI spec. */
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
