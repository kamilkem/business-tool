/**
 * The secret of an invitation or password reset link travels in the URL
 * fragment (`/set-password#token=…`, ADR 0013), which browsers never send to
 * a server. Read it once and take it out of the address bar, so it does not
 * stay in the history, get bookmarked or end up in a screenshot.
 */
let taken: string | null = null

/**
 * Returns the `token` from the URL fragment and removes the fragment with
 * `history.replaceState`. Later calls (React StrictMode runs initializers
 * twice; the router re-runs `beforeLoad`) return the token already taken.
 */
export function takeLinkToken(): string | null {
  const params = new URLSearchParams(window.location.hash.replace(/^#/, ""))
  const token = params.get("token")?.trim()
  if (token) {
    taken = token
    const { pathname, search } = window.location
    // Keep the router's own history state (its entry key and index).
    window.history.replaceState(window.history.state, "", pathname + search)
  }
  return taken
}

/** Forgets the token taken from the link, once it has been used. */
export function forgetLinkToken(): void {
  taken = null
}
