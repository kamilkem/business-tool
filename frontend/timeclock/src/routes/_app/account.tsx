import { Link, createFileRoute } from "@tanstack/react-router"
import { ArrowLeftIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Button } from "@business-tool/ui/components/button"
import { ChangePasswordForm } from "@business-tool/ui/components/change-password-form"
import { LanguageSetting } from "@business-tool/ui/components/language-setting"

import { api } from "@/lib/api"
import { useMeSaved } from "@/lib/use-me-saved"

export const Route = createFileRoute("/_app/account")({
  component: AccountScreen,
})

/** The signed-in person's own account: who they are, their language (ADR 0028) and their password (#16). */
function AccountScreen() {
  const { me } = Route.useRouteContext()
  const onSaved = useMeSaved()
  const { t } = useTranslation("timeclock")

  return (
    <main className="mx-auto flex w-full max-w-sm flex-1 flex-col gap-4 px-4 pb-4">
      <div className="flex items-center gap-1">
        <Button asChild variant="ghost" size="icon" className="-ml-2 size-11">
          <Link to="/" aria-label={t("common.backToClock")}>
            <ArrowLeftIcon />
          </Link>
        </Button>
        <h1 className="text-lg font-semibold">{t("account.title")}</h1>
      </div>

      <div className="rounded-xl border bg-card px-4 py-3 text-sm">
        <div className="font-medium">
          {me.firstName} {me.lastName}
        </div>
        <div className="truncate text-muted-foreground">{me.email}</div>
      </div>

      <section className="rounded-xl border bg-card p-4">
        <LanguageSetting api={api} onSaved={onSaved} touch />
      </section>

      <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
        <h2 className="font-medium">{t("account.changePassword")}</h2>
        <ChangePasswordForm api={api} email={me.email} touch />
      </section>
    </main>
  )
}
