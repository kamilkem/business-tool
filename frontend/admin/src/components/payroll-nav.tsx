import { Link } from "@tanstack/react-router"
import { useTranslation } from "react-i18next"

const linkClass =
  "rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground data-[status=active]:bg-background data-[status=active]:text-foreground data-[status=active]:shadow-sm"

/** The Payroll module's two pages: the month's run and the members' rates. */
export function PayrollNav() {
  const { t } = useTranslation("admin")
  return (
    <nav aria-label={t("nav.payroll")} className="inline-flex w-fit gap-1 rounded-lg bg-muted p-1">
      <Link to="/payroll" activeOptions={{ exact: true, includeSearch: false }} className={linkClass}>
        {t("payroll.nav.paychecks")}
      </Link>
      <Link to="/payroll/rates" className={linkClass}>
        {t("payroll.nav.rates")}
      </Link>
    </nav>
  )
}
