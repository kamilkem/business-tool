import { Link } from "@tanstack/react-router"
import { useTranslation } from "react-i18next"

const linkClass =
  "inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground data-[status=active]:bg-background data-[status=active]:text-foreground data-[status=active]:shadow-sm"

/** The Expenses module's pages: the purchase invoices and the product category tree. The analysis has its own section (#269). */
export function ExpensesNav() {
  const { t } = useTranslation("admin")

  return (
    <nav aria-label={t("nav.expenses")} className="inline-flex w-fit gap-1 rounded-lg bg-muted p-1">
      <Link to="/expenses" activeOptions={{ exact: true, includeSearch: false }} className={linkClass}>
        {t("expenses.nav.invoices")}
      </Link>
      <Link to="/expenses/categories" className={linkClass}>
        {t("expenses.nav.categories")}
      </Link>
    </nav>
  )
}
