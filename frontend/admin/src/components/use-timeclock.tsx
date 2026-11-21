import { useRouteContext } from "@tanstack/react-router"
import { ArrowRightLeftIcon, ExternalLinkIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

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
  const { t } = useTranslation("admin")

  if (!membership) {
    return (
      <div className="mx-auto max-w-md pt-6">
        <Card>
          <CardHeader>
            <CardTitle className="text-xl">{t("useTimeclock.noOrganizationTitle")}</CardTitle>
            <CardDescription>{t("useTimeclock.noOrganizationDescription")}</CardDescription>
          </CardHeader>
        </Card>
      </div>
    )
  }

  return (
    <div className="mx-auto flex max-w-md flex-col gap-4 pt-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-xl">{t("useTimeclock.title")}</CardTitle>
          <CardDescription>
            {t("useTimeclock.description", { organization: membership.organization.name })}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Button asChild>
            <a href={import.meta.env.VITE_TIMECLOCK_URL}>
              {t("useTimeclock.open")}
              <ExternalLinkIcon />
            </a>
          </Button>
        </CardContent>
      </Card>

      {managed.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>{t("useTimeclock.otherOrganizations")}</CardTitle>
            <CardDescription>{t("useTimeclock.otherOrganizationsDescription")}</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-2">
            {managed.map((m) => (
              <Button
                key={m.id}
                variant="outline"
                className="justify-between"
                onClick={() => switchOrganization(m.organization.id)}
              >
                <span className="truncate">{t("useTimeclock.switchTo", { organization: m.organization.name })}</span>
                <ArrowRightLeftIcon />
              </Button>
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  )
}
