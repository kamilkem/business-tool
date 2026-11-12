import { useRouteContext } from "@tanstack/react-router"
import { ArrowRightLeftIcon, ExternalLinkIcon } from "lucide-react"

import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"

import { adminMemberships } from "@/lib/access"
import { useAccountActions } from "@/lib/use-account-actions"

/**
 * The admin's start page for a role that may not use it (ADR 0021): employees are sent to the
 * timeclock, and someone who manages another organization can switch to it. Creating an
 * organization from the admin is off for now; someone with no organization waits for an invitation.
 */
export function UseTimeclock() {
  const { me, membership } = useRouteContext({ from: "/_app" })
  const { switchOrganization } = useAccountActions()
  const managed = adminMemberships(me.memberships)

  if (!membership) {
    return (
      <div className="mx-auto max-w-md pt-6">
        <Card>
          <CardHeader>
            <CardTitle className="text-xl">No organization yet</CardTitle>
            <CardDescription>
              You don't belong to any organization. When a business adds you, you'll get an e-mail invitation.
            </CardDescription>
          </CardHeader>
        </Card>
      </div>
    )
  }

  return (
    <div className="mx-auto flex max-w-md flex-col gap-4 pt-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-xl">Use the timeclock</CardTitle>
          <CardDescription>
            You're an employee of {membership.organization.name}. The admin is for owners and managers; clock in, see
            your shifts and ask for changes in the timeclock app.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Button asChild>
            <a href={import.meta.env.VITE_TIMECLOCK_URL}>
              Open the timeclock
              <ExternalLinkIcon />
            </a>
          </Button>
        </CardContent>
      </Card>

      {managed.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Your other organizations</CardTitle>
            <CardDescription>You can use the admin for these.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-2">
            {managed.map((m) => (
              <Button
                key={m.id}
                variant="outline"
                className="justify-between"
                onClick={() => switchOrganization(m.organization.id)}
              >
                <span className="truncate">Switch to {m.organization.name}</span>
                <ArrowRightLeftIcon />
              </Button>
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  )
}
