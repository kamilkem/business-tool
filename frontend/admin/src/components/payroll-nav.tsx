import { Link } from "@tanstack/react-router"

const linkClass =
  "rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground data-[status=active]:bg-background data-[status=active]:text-foreground data-[status=active]:shadow-sm"

/** The Payroll module's two pages: the month's run and the members' rates. */
export function PayrollNav() {
  return (
    <nav aria-label="Payroll" className="inline-flex w-fit gap-1 rounded-lg bg-muted p-1">
      <Link to="/payroll" activeOptions={{ exact: true, includeSearch: false }} className={linkClass}>
        Paychecks
      </Link>
      <Link to="/payroll/rates" className={linkClass}>
        Rates
      </Link>
    </nav>
  )
}
