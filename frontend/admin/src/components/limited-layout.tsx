import type { ReactNode } from "react"
import { Link, useRouteContext } from "@tanstack/react-router"
import { BuildingIcon, CheckIcon, ChevronsUpDownIcon, HouseIcon, KeyRoundIcon, LogOutIcon } from "lucide-react"

import { Button } from "@business-tool/ui/components/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@business-tool/ui/components/dropdown-menu"

import { roleLabel } from "@/lib/members"
import { useAccountActions } from "@/lib/use-account-actions"

/**
 * The frame for a role that may not use the admin's business screens (an employee of the
 * selected organization, or someone with no organization): no sidebar, only the organization
 * switcher, the account and sign-out (ADR 0021).
 */
export function LimitedLayout({ children }: { children: ReactNode }) {
  const { me, membership } = useRouteContext({ from: "/_app" })
  const { switchOrganization, signOut } = useAccountActions()

  return (
    <div className="flex min-h-svh flex-col bg-muted">
      <header className="flex h-14 shrink-0 items-center gap-2 border-b bg-background px-4">
        <Button variant="ghost" size="icon" className="-ml-2" asChild>
          <Link to="/" aria-label="Start page">
            <HouseIcon />
          </Link>
        </Button>
        {me.memberships.length > 0 ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" className="min-w-0 gap-2">
                <BuildingIcon className="text-muted-foreground" />
                <span className="truncate">{membership?.organization.name}</span>
                <span className="text-xs text-muted-foreground">{membership && roleLabel(membership.role)}</span>
                <ChevronsUpDownIcon className="text-muted-foreground" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="min-w-56">
              <DropdownMenuLabel className="text-xs text-muted-foreground">Organizations</DropdownMenuLabel>
              {me.memberships.map((m) => (
                <DropdownMenuItem key={m.id} onSelect={() => switchOrganization(m.organization.id)}>
                  <span className="flex-1 truncate">{m.organization.name}</span>
                  <span className="text-xs text-muted-foreground">{roleLabel(m.role)}</span>
                  {m.id === membership?.id && <CheckIcon />}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        ) : (
          <span className="text-sm font-medium">Business Tool</span>
        )}

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" className="-mr-2 ml-auto min-w-0">
              <span className="truncate">
                {me.firstName} {me.lastName}
              </span>
              <ChevronsUpDownIcon className="text-muted-foreground" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="min-w-56">
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
            <DropdownMenuItem onSelect={signOut}>
              <LogOutIcon />
              Sign out
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </header>
      <main className="flex-1 p-4 md:p-6">{children}</main>
    </div>
  )
}
