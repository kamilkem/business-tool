import { useQuery } from "@tanstack/react-query"
import { Link, useRouteContext } from "@tanstack/react-router"
import {
  BuildingIcon,
  CheckIcon,
  ChevronsUpDownIcon,
  InboxIcon,
  KeyRoundIcon,
  LayoutDashboardIcon,
  LogOutIcon,
  SettingsIcon,
  UsersIcon,
} from "lucide-react"

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

import { LanguageMenu } from "@/components/language-menu"
import { can } from "@/lib/access"
import { roleLabel } from "@/lib/members"
import { modulesFor } from "@/lib/modules"
import { shiftChangeRequestsQuery } from "@/lib/queries"
import { useAccountActions } from "@/lib/use-account-actions"

export function AppSidebar() {
  const { me, membership } = useRouteContext({ from: "/_app" })
  const { switchOrganization, signOut } = useAccountActions()
  const memberships = me.memberships
  const role = membership?.role

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
                    <span className="truncate text-xs text-muted-foreground">{role && roleLabel(role)}</span>
                  </div>
                  <ChevronsUpDownIcon className="ml-auto" />
                </SidebarMenuButton>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="start" className="min-w-56">
                <DropdownMenuLabel className="text-xs text-muted-foreground">Organizations</DropdownMenuLabel>
                {memberships.map((m) => (
                  <DropdownMenuItem key={m.id} onSelect={() => switchOrganization(m.organization.id)}>
                    <span className="flex-1 truncate">{m.organization.name}</span>
                    <span className="text-xs text-muted-foreground">{roleLabel(m.role)}</span>
                    {m.id === membership?.id && <CheckIcon />}
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
            {can(role, "team") && (
              <SidebarMenuItem>
                <SidebarMenuButton asChild tooltip="Team">
                  <Link to="/team" activeProps={{ "data-active": true }}>
                    <UsersIcon />
                    <span>Team</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
            )}
            {membership && can(role, "shiftRequests") && (
              <ShiftRequestsLink organizationId={membership.organization.id} />
            )}
          </SidebarMenu>
        </SidebarGroup>
        <SidebarGroup>
          <SidebarGroupLabel>Modules</SidebarGroupLabel>
          <SidebarMenu>
            {modulesFor(role).map((module) => (
              <SidebarMenuItem key={module.key}>
                {module.to ? (
                  <SidebarMenuButton asChild tooltip={module.title}>
                    <Link to={module.to} activeProps={{ "data-active": true }}>
                      <module.icon />
                      <span>{module.title}</span>
                    </Link>
                  </SidebarMenuButton>
                ) : (
                  <>
                    <SidebarMenuButton disabled tooltip={module.title}>
                      <module.icon />
                      <span>{module.title}</span>
                    </SidebarMenuButton>
                    <SidebarMenuBadge>
                      <Badge variant="outline" className="text-[10px]">Soon</Badge>
                    </SidebarMenuBadge>
                  </>
                )}
              </SidebarMenuItem>
            ))}
          </SidebarMenu>
        </SidebarGroup>
      </SidebarContent>

      <SidebarFooter>
        <SidebarMenu>
          {can(role, "settings") && (
            <SidebarMenuItem>
              <SidebarMenuButton asChild tooltip="Settings">
                <Link to="/settings" activeProps={{ "data-active": true }}>
                  <SettingsIcon />
                  <span>Organization settings</span>
                </Link>
              </SidebarMenuButton>
            </SidebarMenuItem>
          )}
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
                <DropdownMenuItem asChild>
                  <Link to="/account">
                    <KeyRoundIcon />
                    Account
                  </Link>
                </DropdownMenuItem>
                <LanguageMenu />
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

/** Owners and managers decide shift change requests; the badge counts the pending ones. */
function ShiftRequestsLink({ organizationId }: { organizationId: string }) {
  // Shares the cache with the page's pending list. Polled, so a request sent meanwhile shows up.
  const { data: pending } = useQuery({ ...shiftChangeRequestsQuery(organizationId, "pending"), refetchInterval: 60_000 })
  const count = pending?.length ?? 0

  return (
    <SidebarMenuItem>
      <SidebarMenuButton asChild tooltip={count > 0 ? `Shift requests (${count} pending)` : "Shift requests"}>
        <Link to="/shift-requests" activeProps={{ "data-active": true }}>
          <InboxIcon />
          <span>Shift requests</span>
        </Link>
      </SidebarMenuButton>
      {count > 0 && (
        <SidebarMenuBadge aria-label={`${count} pending`} className="bg-primary text-primary-foreground peer-hover/menu-button:text-primary-foreground peer-data-active/menu-button:text-primary-foreground">
          {count}
        </SidebarMenuBadge>
      )}
    </SidebarMenuItem>
  )
}
