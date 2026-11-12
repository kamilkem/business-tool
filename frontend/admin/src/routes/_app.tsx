import { Outlet, createFileRoute, redirect } from "@tanstack/react-router"

import { SidebarInset, SidebarProvider, SidebarTrigger } from "@business-tool/ui/components/sidebar"
import { Separator } from "@business-tool/ui/components/separator"
import { OrganizationCurrencyContext } from "@business-tool/ui/hooks/use-organization-currency"
import { OrganizationTimeZoneContext } from "@business-tool/ui/hooks/use-organization-time-zone"

import { AppSidebar } from "@/components/app-sidebar"
import { LimitedLayout } from "@/components/limited-layout"
import { can } from "@/lib/access"
import { meQuery } from "@/lib/queries"

/**
 * Authenticated layout: requires a token and selects an organization. Owners and managers of the
 * selected organization get the sidebar; anyone else gets the limited frame (ADR 0021), where the
 * start page points to the timeclock and only the account page is open. Each business page guards
 * itself with requireCapability().
 */
export const Route = createFileRoute("/_app")({
  beforeLoad: async ({ context, location }) => {
    const { session, queryClient } = context
    if (!session.get().token) {
      throw redirect({ to: "/login", search: { redirect: location.href } })
    }

    const me = await queryClient.ensureQueryData(meQuery)
    const memberships = me.memberships
    const selected = session.get().organizationId
    const current = memberships.find((m) => m.organization.id === selected) ?? memberships[0]
    if (current && current.organization.id !== selected) {
      session.selectOrganization(current.organization.id)
    }

    return { me, membership: current ?? null }
  },
  component: AppLayout,
})

function AppLayout() {
  const { membership } = Route.useRouteContext()

  if (!membership || !can(membership.role, "admin")) {
    return (
      <LimitedLayout>
        <Outlet />
      </LimitedLayout>
    )
  }

  return (
    // Dates and times on every page are shown in the organization's time zone (ADR 0002 §4), and
    // money in its currency (ADR 0010).
    <OrganizationTimeZoneContext value={membership.organization.timezone}>
      <OrganizationCurrencyContext value={membership.organization.currency}>
        <SidebarProvider>
          <AppSidebar />
          {/* min-w-0 lets wide content (tables) scroll inside the page instead of widening it. */}
          <SidebarInset className="min-w-0">
            <header className="flex h-14 shrink-0 items-center gap-2 border-b px-4">
              <SidebarTrigger className="-ml-1" />
              <Separator orientation="vertical" className="mr-2 data-[orientation=vertical]:h-4" />
              <span className="text-sm font-medium">{membership.organization.name}</span>
            </header>
            <main className="flex-1 p-4 md:p-6">
              <Outlet />
            </main>
          </SidebarInset>
        </SidebarProvider>
      </OrganizationCurrencyContext>
    </OrganizationTimeZoneContext>
  )
}
