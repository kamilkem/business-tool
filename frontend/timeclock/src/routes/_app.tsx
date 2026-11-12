import { logout, signInAgainUrl } from "@business-tool/api-client"
import { Outlet, createFileRoute, redirect, useRouter } from "@tanstack/react-router"
import { LogOutIcon } from "lucide-react"

import { Button } from "@business-tool/ui/components/button"
import { OrganizationTimeZoneContext } from "@business-tool/ui/hooks/use-organization-time-zone"

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

  async function signOut() {
    await logout(session)
    queryClient.clear()
    await router.navigate({ to: "/login" })
  }

  return (
    <div className="flex min-h-svh flex-col bg-muted pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)]">
      <header className="flex items-center justify-between gap-2 py-2 pl-4 pr-2">
        <div className="min-w-0 text-sm">
          <div className="truncate font-medium">{membership?.organization.name ?? "No organization"}</div>
          <div className="truncate text-muted-foreground">
            {me.firstName} {me.lastName}
          </div>
        </div>
        <Button variant="ghost" size="icon" className="size-11" onClick={signOut} aria-label="Sign out">
          <LogOutIcon />
        </Button>
      </header>

      {membership ? (
        <OrganizationTimeZoneContext value={membership.organization.timezone}>
          <Outlet />
        </OrganizationTimeZoneContext>
      ) : (
        <main className="flex flex-1 flex-col items-center justify-center px-4 text-center">
          <p className="max-w-xs text-muted-foreground">
            You aren't a member of any organization, so there is nothing to clock in to. Ask your manager to add you.
          </p>
        </main>
      )}
    </div>
  )
}
