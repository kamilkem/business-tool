import { createFileRoute, redirect } from "@tanstack/react-router"

import { validateAnalysisSearch, type AnalysisSearch } from "@/lib/purchase-analysis"

/** The overview's old address (before #269), kept for bookmarks and links: sends on to /analysis with its period. */
export const Route = createFileRoute("/_app/expenses/analysis/")({
  validateSearch: (search: Record<string, unknown>): AnalysisSearch => validateAnalysisSearch(search),
  beforeLoad: ({ search }) => {
    throw redirect({ to: "/analysis", search, replace: true })
  },
})
