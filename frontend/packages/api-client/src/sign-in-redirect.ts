/**
 * Where to go after signing in again. When the session ends for good (the
 * refresh token is refused: ADR 0020), a 401 sends the person to the sign-in
 * page, and the page they were on travels along in `?redirect=` so they land
 * back on it (the timeclock's clock screen: ADR 0016).
 */

/** The sign-in page, remembering the current page (path and query) to return to. */
export function signInAgainUrl(location: Pick<Location, "pathname" | "search"> = window.location): string {
  const back = location.pathname + location.search
  if (back === "/" || location.pathname === "/login") return "/login"
  return `/login?redirect=${encodeURIComponent(back)}`
}

/**
 * The `redirect` search param if it is a path in this app, otherwise "/".
 * A value like `//evil.example` or `https://…` is another origin, never followed.
 */
export function safeRedirectPath(redirect: string | undefined): string {
  if (!redirect || !redirect.startsWith("/") || redirect.startsWith("//") || redirect.startsWith("/\\")) return "/"
  return redirect
}
