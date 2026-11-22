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
import { useTranslation } from "react-i18next"

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
import { type ModuleInfo, modulesFor } from "@/lib/modules"
import { shiftChangeRequestsQuery } from "@/lib/queries"
import { useAccountActions } from "@/lib/use-account-actions"

export function AppSidebar() {
  const { t } = useTranslation("admin")
  const { me, membership } = useRouteContext({ from: "/_app" })
  const { switchOrganization, signOut } = useAccountActions()
  const memberships = me.memberships
  const role = membership?.role
  const finances = modulesFor(role, "finances")
  const staff = modulesFor(role, "staff")
  const showTeam = can(role, "team")
  const showShiftRequests = can(role, "shiftRequests")

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
                <DropdownMenuLabel className="text-xs text-muted-foreground">{t("account.organizations")}</DropdownMenuLabel>
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
              <SidebarMenuButton asChild tooltip={t("nav.dashboard")}>
                <Link to="/" activeOptions={{ exact: true }} activeProps={{ "data-active": true }}>
                  <LayoutDashboardIcon />
                  <span>{t("nav.dashboard")}</span>
                </Link>
              </SidebarMenuButton>
            </SidebarMenuItem>
          </SidebarMenu>
        </SidebarGroup>
        {finances.length > 0 && (
          <SidebarGroup>
            <SidebarGroupLabel>{t("nav.groups.finances")}</SidebarGroupLabel>
            <SidebarMenu>
              {finances.map((module) => (
                <ModuleLink key={module.key} module={module} />
              ))}
            </SidebarMenu>
          </SidebarGroup>
        )}
        {(showTeam || staff.length > 0 || showShiftRequests) && (
          <SidebarGroup>
            <SidebarGroupLabel>{t("nav.groups.staff")}</SidebarGroupLabel>
            <SidebarMenu>
              {showTeam && (
                <SidebarMenuItem>
                  <SidebarMenuButton asChild tooltip={t("nav.team")}>
                    <Link to="/team" activeProps={{ "data-active": true }}>
                      <UsersIcon />
                      <span>{t("nav.team")}</span>
                    </Link>
                  </SidebarMenuButton>
                </SidebarMenuItem>
              )}
              {staff.map((module) => (
                <ModuleLink key={module.key} module={module} />
              ))}
              {membership && showShiftRequests && <ShiftRequestsLink organizationId={membership.organization.id} />}
            </SidebarMenu>
          </SidebarGroup>
        )}
      </SidebarContent>

      <SidebarFooter>
        <SidebarMenu>
          {can(role, "settings") && (
            <SidebarMenuItem>
              <SidebarMenuButton asChild tooltip={t("nav.settings")}>
                <Link to="/settings" activeProps={{ "data-active": true }}>
                  <SettingsIcon />
                  <span>{t("nav.settings")}</span>
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
                    {t("account.account")}
                  </Link>
                </DropdownMenuItem>
                <LanguageMenu />
                <DropdownMenuItem onSelect={signOut}>
                  <LogOutIcon />
                  {t("account.signOut")}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarFooter>
    </Sidebar>
  )
}

/** A module's link, or a disabled "soon" entry until its page is built. */
function ModuleLink({ module }: { module: ModuleInfo }) {
  const { t } = useTranslation("admin")
  const title = t(`modules.${module.key}.title`)

  return (
    <SidebarMenuItem>
      {module.to ? (
        <SidebarMenuButton asChild tooltip={title}>
          <Link to={module.to} activeProps={{ "data-active": true }}>
            <module.icon />
            <span>{title}</span>
          </Link>
        </SidebarMenuButton>
      ) : (
        <>
          <SidebarMenuButton disabled tooltip={title}>
            <module.icon />
            <span>{title}</span>
          </SidebarMenuButton>
          <SidebarMenuBadge>
            <Badge variant="outline" className="text-[10px]">{t("nav.soon")}</Badge>
          </SidebarMenuBadge>
        </>
      )}
    </SidebarMenuItem>
  )
}

/** Owners and managers decide shift change requests; the badge counts the pending ones. */
function ShiftRequestsLink({ organizationId }: { organizationId: string }) {
  // Shares the cache with the page's pending list. Polled, so a request sent meanwhile shows up.
  const { data: pending } = useQuery({ ...shiftChangeRequestsQuery(organizationId, "pending"), refetchInterval: 60_000 })
  const count = pending?.length ?? 0
  const { t } = useTranslation("admin")

  return (
    <SidebarMenuItem>
      <SidebarMenuButton asChild tooltip={count > 0 ? t("nav.shiftRequestsPending", { count }) : t("nav.shiftRequests")}>
        <Link to="/shift-requests" activeProps={{ "data-active": true }}>
          <InboxIcon />
          <span>{t("nav.shiftRequests")}</span>
        </Link>
      </SidebarMenuButton>
      {count > 0 && (
        <SidebarMenuBadge aria-label={t("nav.pending", { count })} className="bg-primary text-primary-foreground peer-hover/menu-button:text-primary-foreground peer-data-active/menu-button:text-primary-foreground">
          {count}
        </SidebarMenuBadge>
      )}
    </SidebarMenuItem>
  )
}
