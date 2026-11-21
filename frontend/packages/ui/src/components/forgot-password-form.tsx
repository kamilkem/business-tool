import { useState, type FormEvent } from "react"
import { type ApiClient, type components, unwrap, violationsOf } from "@business-tool/api-client"
import { CircleAlertIcon, MailCheckIcon } from "lucide-react"
import { Trans, useTranslation } from "react-i18next"

import { Alert, AlertDescription, AlertTitle } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Field, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

type ClientApp = components["schemas"]["Password.RequestPasswordResetInput"]["app"]

interface Errors {
  email?: string
  form?: string
}

export interface ForgotPasswordFormProps {
  api: ApiClient
  /** The app whose "set a new password" page the e-mailed link opens. */
  app: ClientApp
}

/**
 * Asks for a password reset link (`POST /api/auth/password-reset`). The API
 * answers the same whether or not the e-mail has an account, and so does this
 * form: it never tells who signs in here.
 */
export function ForgotPasswordForm({ api, app }: ForgotPasswordFormProps) {
  const { t } = useTranslation()
  const [sentTo, setSentTo] = useState<string | null>(null)
  const [errors, setErrors] = useState<Errors>({})
  const [pending, setPending] = useState(false)

  if (sentTo !== null) {
    return (
      <Alert>
        <MailCheckIcon />
        <AlertTitle>{t("password.forgot.sentTitle")}</AlertTitle>
        <AlertDescription className="text-pretty">
          <p>
            <Trans
              t={t}
              i18nKey="password.forgot.sentDescription"
              values={{ email: sentTo }}
              components={{ email: <span className="font-medium text-foreground break-all" /> }}
            />
          </p>
          <p>
            <Trans
              t={t}
              i18nKey="password.forgot.nothingArrived"
              components={{
                retry: (
                  <button
                    type="button"
                    className="underline underline-offset-3 hover:text-foreground"
                    onClick={() => setSentTo(null)}
                  />
                ),
              }}
            />
          </p>
        </AlertDescription>
      </Alert>
    )
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const email = String(new FormData(event.currentTarget).get("email") ?? "").trim()
    if (email === "") {
      setErrors({ email: t("password.forgot.emailMissing") })
      return
    }

    setPending(true)
    setErrors({})
    try {
      await unwrap(api.POST("/api/auth/password-reset", { body: { email, app } }))
      setSentTo(email)
    } catch (error) {
      const violations = violationsOf(error)
      if (violations.email) {
        setErrors({ email: violations.email })
      } else {
        setErrors({ form: Object.values(violations)[0] ?? requestErrorMessage(error) })
      }
    } finally {
      setPending(false)
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <FieldGroup>
        <Field data-invalid={errors.email ? true : undefined}>
          <FieldLabel htmlFor="email">{t("common.email")}</FieldLabel>
          <Input
            id="email"
            name="email"
            type="email"
            inputMode="email"
            autoComplete="username"
            required
            autoFocus
            aria-invalid={errors.email ? true : undefined}
          />
          {errors.email && <FieldError>{errors.email}</FieldError>}
        </Field>
        {errors.form && (
          <Alert variant="destructive">
            <CircleAlertIcon />
            <AlertDescription className="text-pretty">{errors.form}</AlertDescription>
          </Alert>
        )}
        <Button type="submit" disabled={pending}>
          {pending ? t("common.sending") : t("password.forgot.submit")}
        </Button>
      </FieldGroup>
    </form>
  )
}
