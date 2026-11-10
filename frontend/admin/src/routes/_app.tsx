import { Outlet, createFileRoute, redirect } from "@tanstack/react-router"

import { SidebarInset, SidebarProvider, SidebarTrigger } from "@business-tool/ui/components/sidebar"
import { Separator } from "@business-tool/ui/components/separator"

import { AppSidebar } from "@/components/app-sidebar"
import { CreateOrganization } from "@/components/create-organization"
import { meQuery } from "@/lib/queries"

/** Authenticated layout: requires a token and selects an organization. */
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

  if (!membership) {
    return <CreateOrganization />
  }

  return (
    <SidebarProvider>
      <AppSidebar />
      <SidebarInset>
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
  )
}
