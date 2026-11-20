import { Link } from "@tanstack/react-router"

const linkClass =
  "rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground data-[status=active]:bg-background data-[status=active]:text-foreground data-[status=active]:shadow-sm"

/** The Expenses module's two pages: the purchase invoices and the categories they are sorted into. */
export function ExpensesNav() {
  return (
    <nav aria-label="Expenses" className="inline-flex w-fit gap-1 rounded-lg bg-muted p-1">
      <Link to="/expenses" activeOptions={{ exact: true, includeSearch: false }} className={linkClass}>
        Invoices
      </Link>
      <Link to="/expenses/categories" className={linkClass}>
        Categories
      </Link>
    </nav>
  )
}
