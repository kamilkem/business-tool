/*
 * The Analysis section's navigation (#269): three tabs, Overview, Review queue and Proposed products,
 * with the product detail page inside the section under Overview.
 */

/** The most a tab's badge writes out; more is "99+". */
export const BADGE_MAX = 99

/** What a tab's badge shows for a count, or `null` for none: nothing while it loads, fails, or is zero. */
export function badgeText(count: number | undefined): string | null {
  if (count === undefined || count <= 0) return null
  return count > BADGE_MAX ? `${BADGE_MAX}+` : String(count)
}

/** The overview's tab is the active one on the overview and on a product's page, which opens from it. */
export function isOverviewPath(pathname: string): boolean {
  const path = pathname.length > 1 ? pathname.replace(/\/+$/, "") : pathname
  return path === "/analysis" || path.startsWith("/analysis/products/")
}
