import { createFileRoute } from "@tanstack/react-router"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { ChangePasswordForm } from "@business-tool/ui/components/change-password-form"

import { api } from "@/lib/api"

export const Route = createFileRoute("/_app/account")({
  component: AccountPage,
})

/** The signed-in person's own account: who they are and their password (#16). */
function AccountPage() {
  const { me } = Route.useRouteContext()

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-4">
      <Card>
        <CardHeader>
          <CardTitle>Account</CardTitle>
          <CardDescription>
            {me.firstName} {me.lastName} · {me.email}
          </CardDescription>
        </CardHeader>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>Change password</CardTitle>
          <CardDescription>You stay signed in here; your other devices and apps are signed out.</CardDescription>
        </CardHeader>
        <CardContent>
          <ChangePasswordForm api={api} email={me.email} />
        </CardContent>
      </Card>
    </div>
  )
}
