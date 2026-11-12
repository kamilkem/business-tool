import { Link } from "@tanstack/react-router"

const linkClass =
  "rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground data-[status=active]:bg-background data-[status=active]:text-foreground data-[status=active]:shadow-sm"

/** The Income module's two pages: the daily entries and the registers they are entered for. */
export function IncomeNav() {
  return (
    <nav aria-label="Income" className="inline-flex w-fit gap-1 rounded-lg bg-muted p-1">
      <Link to="/income" activeOptions={{ exact: true, includeSearch: false }} className={linkClass}>
        Daily entries
      </Link>
      <Link to="/income/registers" className={linkClass}>
        Registers
      </Link>
    </nav>
  )
}
