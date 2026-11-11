import { Link, createFileRoute } from "@tanstack/react-router"

import { AuthScreen } from "@business-tool/ui/components/auth-screen"
import { ForgotPasswordForm } from "@business-tool/ui/components/forgot-password-form"

import { api } from "@/lib/api"

/** Public page: e-mails a link that opens this app's /set-password. */
export const Route = createFileRoute("/forgot-password")({
  component: ForgotPasswordPage,
})

function ForgotPasswordPage() {
  return (
    <AuthScreen
      title="Forgot your password?"
      description="Enter the e-mail you sign in with and we'll send you a link to set a new password."
      footer={
        <Link to="/login" className="underline-offset-4 hover:underline">
          Back to sign in
        </Link>
      }
    >
      <ForgotPasswordForm api={api} app="timeclock" />
    </AuthScreen>
  )
}
