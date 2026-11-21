import { Link } from "@tanstack/react-router"
import { useTranslation } from "react-i18next"

const linkClass =
  "rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground data-[status=active]:bg-background data-[status=active]:text-foreground data-[status=active]:shadow-sm"

/** The Income module's pages: the daily entries, the registers they are entered for and the payment methods they use. */
export function IncomeNav() {
  const { t } = useTranslation("admin")
  return (
    <nav aria-label={t("nav.income")} className="inline-flex w-fit max-w-full flex-wrap gap-1 rounded-lg bg-muted p-1">
      <Link to="/income" activeOptions={{ exact: true, includeSearch: false }} className={linkClass}>
        {t("income.nav.dailyEntries")}
      </Link>
      <Link to="/income/registers" className={linkClass}>
        {t("income.nav.registers")}
      </Link>
      <Link to="/income/payment-methods" className={linkClass}>
        {t("income.nav.paymentMethods")}
      </Link>
    </nav>
  )
}
