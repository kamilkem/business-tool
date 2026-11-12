import { Link, createFileRoute } from "@tanstack/react-router"
import { ArrowLeftIcon } from "lucide-react"

import { Button } from "@business-tool/ui/components/button"
import { ChangePasswordForm } from "@business-tool/ui/components/change-password-form"

import { api } from "@/lib/api"

export const Route = createFileRoute("/_app/account")({
  component: AccountScreen,
})

/** The signed-in person's own account: who they are and their password (#16). */
function AccountScreen() {
  const { me } = Route.useRouteContext()

  return (
    <main className="mx-auto flex w-full max-w-sm flex-1 flex-col gap-4 px-4 pb-4">
      <div className="flex items-center gap-1">
        <Button asChild variant="ghost" size="icon" className="-ml-2 size-11">
          <Link to="/" aria-label="Back to the clock">
            <ArrowLeftIcon />
          </Link>
        </Button>
        <h1 className="text-lg font-semibold">Account</h1>
      </div>

      <div className="rounded-xl border bg-card px-4 py-3 text-sm">
        <div className="font-medium">
          {me.firstName} {me.lastName}
        </div>
        <div className="truncate text-muted-foreground">{me.email}</div>
      </div>

      <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
        <h2 className="font-medium">Change password</h2>
        <ChangePasswordForm api={api} email={me.email} touch />
      </section>
    </main>
  )
}
