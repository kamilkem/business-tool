import type { Session } from "./session"

/**
 * Silent refresh (ADR 0020). The refresh token lives in an HttpOnly cookie
 * scoped to /api/auth, so the browser sends it and scripts never see it;
 * `POST /api/auth/refresh` answers with a new JWT and a rotated cookie.
 */

export type RefreshOutcome =
  /** A new JWT, already stored in the session. */
  | { status: "refreshed"; token: string }
  /** The API refused the cookie (expired, revoked, missing): sign in again. */
  | { status: "rejected" }
  /** Network or server error: keep the session, a later request may refresh. */
  | { status: "unavailable" }

export interface TokenRefresherOptions {
  baseUrl?: string
  session: Session
  fetch?: typeof globalThis.fetch
}

/**
 * Returns `refresh()`. Calls made while a refresh is in flight share it, so
 * concurrent 401s rotate the cookie once.
 */
export function createTokenRefresher({
  baseUrl = "",
  session,
  fetch = globalThis.fetch,
}: TokenRefresherOptions): () => Promise<RefreshOutcome> {
  let inFlight: Promise<RefreshOutcome> | null = null

  async function exchange(): Promise<RefreshOutcome> {
    let response: Response
    try {
      // Same origin (ADR 0020): the default credentials mode sends the cookie.
      response = await fetch(`${baseUrl}/api/auth/refresh`, {
        method: "POST",
        headers: { Accept: "application/json" },
        credentials: "same-origin",
      })
    } catch {
      return { status: "unavailable" }
    }
    if (response.status === 401) return { status: "rejected" }
    if (!response.ok) return { status: "unavailable" }

    const body: unknown = await response.json().catch(() => null)
    const token = typeof body === "object" && body !== null && "token" in body ? (body as { token: unknown }).token : null
    if (typeof token !== "string" || token === "") return { status: "unavailable" }

    session.signIn(token)
    return { status: "refreshed", token }
  }

  return () => {
    inFlight ??= exchange().finally(() => {
      inFlight = null
    })
    return inFlight
  }
}

/**
 * Signs out of this app: revokes the session behind the cookie (ADR 0020),
 * then clears the local session even when the API cannot be reached.
 */
export async function logout(session: Session, baseUrl = "", fetch = globalThis.fetch): Promise<void> {
  try {
    await fetch(`${baseUrl}/api/auth/logout`, { method: "POST", credentials: "same-origin" })
  } catch {
    // Offline: the cookie's session ends when it expires or is reused.
  }
  session.signOut()
}
