import { createFileRoute, redirect } from "@tanstack/react-router"

/**
 * The old addresses below /expenses/analysis (before #269: /review and /products/<id>), kept for
 * bookmarks and links: the rest of the path and the query string move to /analysis unchanged, and the
 * new route validates them. An address that was never a page ends up on the new section's not-found page.
 */
export const Route = createFileRoute("/_app/expenses/analysis/$")({
  beforeLoad: ({ params, location }) => {
    throw redirect({ href: redirectHref(params._splat ?? "", location.searchStr), replace: true })
  },
})

function redirectHref(rest: string, searchStr: string): string {
  const path = rest.replace(/^\/+|\/+$/g, "")
  return `/analysis${path === "" ? "" : `/${path}`}${searchStr}`
}
