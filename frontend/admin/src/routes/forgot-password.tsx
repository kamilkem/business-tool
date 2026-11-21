import { Link, createFileRoute } from "@tanstack/react-router"
import { useTranslation } from "react-i18next"

import { AuthScreen } from "@business-tool/ui/components/auth-screen"
import { ForgotPasswordForm } from "@business-tool/ui/components/forgot-password-form"

import { api } from "@/lib/api"

/** Public page: e-mails a link that opens this app's /set-password. */
export const Route = createFileRoute("/forgot-password")({
  component: ForgotPasswordPage,
})

function ForgotPasswordPage() {
  const { t } = useTranslation("admin")
  return (
    <AuthScreen
      title={t("forgotPassword.title")}
      description={t("forgotPassword.description")}
      footer={
        <Link to="/login" className="underline-offset-4 hover:underline">
          {t("common.backToSignIn")}
        </Link>
      }
    >
      <ForgotPasswordForm api={api} app="admin" />
    </AuthScreen>
  )
}
