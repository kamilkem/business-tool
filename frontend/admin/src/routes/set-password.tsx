import { forgetLinkToken, takeLinkToken } from "@business-tool/api-client"
import { Link, createFileRoute, useNavigate } from "@tanstack/react-router"

import { AuthScreen } from "@business-tool/ui/components/auth-screen"
import { SetPasswordForm } from "@business-tool/ui/components/set-password-form"

import { api } from "@/lib/api"

/**
 * Public page that invitation and password reset e-mails link to
 * (`/set-password#token=…`, ADR 0013). It works signed in or not.
 */
export const Route = createFileRoute("/set-password")({
  beforeLoad: () => ({ linkToken: takeLinkToken() }),
  component: SetPasswordPage,
})

function SetPasswordPage() {
  const { linkToken, session, queryClient } = Route.useRouteContext()
  const navigate = useNavigate()

  async function onPasswordSet() {
    forgetLinkToken()
    // Setting a password does not sign anyone in: whoever was signed in here signs in again.
    session.signOut()
    queryClient.clear()
    await navigate({ to: "/login", search: { passwordSet: true } })
  }

  return (
    <AuthScreen
      title="Set your password"
      description="Choose the password you'll use to sign in to the admin panel."
      footer={
        <Link to="/login" className="underline-offset-4 hover:underline">
          Back to sign in
        </Link>
      }
    >
      <SetPasswordForm
        api={api}
        token={linkToken}
        onPasswordSet={onPasswordSet}
        requestNewLink={
          <Link to="/forgot-password" className="text-foreground">
            request a new link
          </Link>
        }
      />
    </AuthScreen>
  )
}
