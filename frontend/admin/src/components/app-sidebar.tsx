import { Link, useRouteContext, useRouter } from "@tanstack/react-router"
import { BuildingIcon, ChevronsUpDownIcon, LayoutDashboardIcon, LogOutIcon, SettingsIcon, UsersIcon } from "lucide-react"

import { Badge } from "@business-tool/ui/components/badge"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@business-tool/ui/components/dropdown-menu"
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuBadge,
  SidebarMenuButton,
  SidebarMenuItem,
} from "@business-tool/ui/components/sidebar"

import { canManageTeam } from "@/lib/members"
import { modules } from "@/lib/modules"

export function AppSidebar() {
  const { me, membership, session, queryClient } = useRouteContext({ from: "/_app" })
  const router = useRouter()
  const memberships = me.memberships

  async function switchOrganization(id: string) {
    session.selectOrganization(id)
    // Drop cached tenant data before reloading routes for the new organization.
    queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== "me" })
    await router.invalidate()
  }

  async function signOut() {
    session.signOut()
    queryClient.clear()
    await router.navigate({ to: "/login" })
  }

  return (
    <Sidebar collapsible="icon">
      <SidebarHeader>
        <SidebarMenu>
          <SidebarMenuItem>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <SidebarMenuButton size="lg">
                  <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                    <BuildingIcon className="size-4" />
                  </div>
                  <div className="grid flex-1 text-left text-sm leading-tight">
                    <span className="truncate font-medium">{membership?.organization.name}</span>
                    <span className="truncate text-xs capitalize text-muted-foreground">{membership?.role}</span>
                  </div>
                  <ChevronsUpDownIcon className="ml-auto" />
                </SidebarMenuButton>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="start" className="min-w-56">
                <DropdownMenuLabel className="text-xs text-muted-foreground">Organizations</DropdownMenuLabel>
                {memberships.map((m) => (
                  <DropdownMenuItem key={m.id} onSelect={() => switchOrganization(m.organization.id)}>
                    {m.organization.name}
                  </DropdownMenuItem>
                ))}
              </DropdownMenuContent>
            </DropdownMenu>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>

      <SidebarContent>
        <SidebarGroup>
          <SidebarMenu>
            <SidebarMenuItem>
              <SidebarMenuButton asChild tooltip="Dashboard">
                <Link to="/" activeOptions={{ exact: true }} activeProps={{ "data-active": true }}>
                  <LayoutDashboardIcon />
                  <span>Dashboard</span>
                </Link>
              </SidebarMenuButton>
            </SidebarMenuItem>
            {canManageTeam(membership?.role) && (
              <SidebarMenuItem>
                <SidebarMenuButton asChild tooltip="Team">
                  <Link to="/team" activeProps={{ "data-active": true }}>
                    <UsersIcon />
                    <span>Team</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
            )}
          </SidebarMenu>
        </SidebarGroup>
        <SidebarGroup>
          <SidebarGroupLabel>Modules</SidebarGroupLabel>
          <SidebarMenu>
            {modules.map((module) => (
              <SidebarMenuItem key={module.key}>
                <SidebarMenuButton disabled tooltip={module.title}>
                  <module.icon />
                  <span>{module.title}</span>
                </SidebarMenuButton>
                <SidebarMenuBadge>
                  <Badge variant="outline" className="text-[10px]">Soon</Badge>
                </SidebarMenuBadge>
              </SidebarMenuItem>
            ))}
          </SidebarMenu>
        </SidebarGroup>
      </SidebarContent>

      <SidebarFooter>
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton asChild tooltip="Settings">
              <Link to="/settings" activeProps={{ "data-active": true }}>
                <SettingsIcon />
                <span>Organization settings</span>
              </Link>
            </SidebarMenuButton>
          </SidebarMenuItem>
          <SidebarMenuItem>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <SidebarMenuButton>
                  <span className="truncate">
                    {me.firstName} {me.lastName}
                  </span>
                  <ChevronsUpDownIcon className="ml-auto" />
                </SidebarMenuButton>
              </DropdownMenuTrigger>
              <DropdownMenuContent side="top" align="start" className="min-w-56">
                <DropdownMenuLabel className="font-normal">
                  <div className="text-sm font-medium">
                    {me.firstName} {me.lastName}
                  </div>
                  <div className="text-xs text-muted-foreground">{me.email}</div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={signOut}>
                  <LogOutIcon />
                  Sign out
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarFooter>
    </Sidebar>
  )
}
