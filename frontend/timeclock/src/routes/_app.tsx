import { logout, signInAgainUrl } from "@business-tool/api-client"
import { Link, Outlet, createFileRoute, redirect, useMatchRoute, useRouter } from "@tanstack/react-router"
import { LogOutIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Button } from "@business-tool/ui/components/button"
import { OrganizationTimeZoneContext } from "@business-tool/ui/hooks/use-organization-time-zone"
import { languageForUser, showLanguage } from "@business-tool/ui/i18n/current-language"

import { meQuery } from "@/lib/queries"

/**
 * Signed-in screens: requires a session, loads /me and selects an organization. The header shows
 * who and where; the screens below get the organization's time zone (ADR 0019).
 */
export const Route = createFileRoute("/_app")({
  beforeLoad: async ({ context, location }) => {
    const { session, queryClient } = context
    if (!session.get().token) {
      throw redirect({ to: "/login", search: { redirect: location.href } })
    }

    let me
    try {
      me = await queryClient.ensureQueryData(meQuery)
    } catch (error) {
      // The token expired: the API client signed out and cleared the cache,
      // which cancels this query, so the error may be a 401 or a cancellation.
      // Sign in again, then come back here (ADR 0016).
      if (!session.get().token) {
        throw redirect({ href: signInAgainUrl({ pathname: location.pathname, search: location.searchStr }) })
      }
      throw error
    }
    // The user's own language, else the browser's (ADR 0028 §2); a no-op when it is already shown.
    showLanguage(languageForUser(me.language))
    const memberships = me.memberships
    const selected = session.get().organizationId
    const membership = memberships.find((m) => m.organization.id === selected) ?? memberships[0] ?? null
    if (membership && membership.organization.id !== selected) {
      session.selectOrganization(membership.organization.id)
    }

    return { me, membership }
  },
  component: AppLayout,
})

function AppLayout() {
  const { me, membership, session, queryClient } = Route.useRouteContext()
  const router = useRouter()
  const { t } = useTranslation("timeclock")
  // The account screen works without an organization; the others need one.
  const onAccount = Boolean(useMatchRoute()({ to: "/account" }))

  async function signOut() {
    await logout(session)
    queryClient.clear()
    await router.navigate({ to: "/login" })
  }

  return (
    <div className="flex min-h-svh flex-col bg-muted pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)]">
      <header className="flex items-center justify-between gap-2 py-2 pl-4 pr-2">
        <Link to="/account" className="-my-1 min-w-0 rounded-md py-1 text-sm" aria-label={t("layout.account")}>
          <div className="truncate font-medium">{membership?.organization.name ?? t("layout.noOrganization")}</div>
          <div className="truncate text-muted-foreground underline-offset-4 hover:underline">
            {me.firstName} {me.lastName}
          </div>
        </Link>
        <Button variant="ghost" size="icon" className="size-11" onClick={signOut} aria-label={t("layout.signOut")}>
          <LogOutIcon />
        </Button>
      </header>

      {membership ? (
        <OrganizationTimeZoneContext value={membership.organization.timezone}>
          <Outlet />
        </OrganizationTimeZoneContext>
      ) : onAccount ? (
        <Outlet />
      ) : (
        <main className="flex flex-1 flex-col items-center justify-center px-4 text-center">
          <p className="max-w-xs text-muted-foreground">
            {t("layout.notAMember")}
          </p>
        </main>
      )}
    </div>
  )
}
