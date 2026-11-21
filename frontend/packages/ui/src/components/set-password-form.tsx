import { useState, type FormEvent, type ReactElement } from "react"
import { ApiError, type ApiClient, unwrap, violationsOf } from "@business-tool/api-client"
import { Trans, useTranslation } from "react-i18next"
import { CircleAlertIcon } from "lucide-react"

import { Alert, AlertDescription, AlertTitle } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

/** Mirrors the API's rule (at least 8 characters, no composition rules); the API has the last word. */
const MIN_PASSWORD_LENGTH = 8

interface Errors {
  password?: string
  confirmation?: string
  form?: string
}

export interface SetPasswordFormProps {
  api: ApiClient
  /** The secret from the link's fragment, or null when the page was opened without one. */
  token: string | null
  /** Runs after the API set the password (204); the person signs in next. */
  onPasswordSet: () => void | Promise<void>
  /**
   * A link to the "forgot password" form, offered when the link no longer works. Its text comes from
   * the catalog ("request a new link"), so the element's own children are replaced.
   */
  requestNewLink: ReactElement
}

/**
 * Sets a password with the token from an invitation or password reset e-mail
 * (`POST /api/auth/set-password`, ADR 0013). An unknown, used or expired link
 * is one and the same 404, so the page cannot say which one it was.
 */
export function SetPasswordForm({ api, token, onPasswordSet, requestNewLink }: SetPasswordFormProps) {
  const { t } = useTranslation()
  const [linkInvalid, setLinkInvalid] = useState(token === null)
  const [errors, setErrors] = useState<Errors>({})
  const [pending, setPending] = useState(false)

  if (linkInvalid || token === null) {
    return <InvalidLinkNotice requestNewLink={requestNewLink} />
  }
  const secret: string = token

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const password = String(form.get("password") ?? "")
    const confirmation = String(form.get("confirmation") ?? "")

    const local: Errors = {}
    if (password.length < MIN_PASSWORD_LENGTH) {
      local.password = t("password.error.tooShort", { count: MIN_PASSWORD_LENGTH })
    } else if (confirmation !== password) {
      local.confirmation = t("password.error.mismatch")
    }
    setErrors(local)
    if (local.password || local.confirmation) return

    setPending(true)
    try {
      await unwrap(api.POST("/api/auth/set-password", { body: { token: secret, password } }))
      await onPasswordSet()
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) {
        setLinkInvalid(true)
        return
      }
      const violations = violationsOf(error)
      if (violations.token) {
        setLinkInvalid(true)
      } else if (violations.password) {
        setErrors({ password: violations.password })
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
        <Field data-invalid={errors.password ? true : undefined}>
          <FieldLabel htmlFor="password">{t("password.new")}</FieldLabel>
          <Input
            id="password"
            name="password"
            type="password"
            autoComplete="new-password"
            minLength={MIN_PASSWORD_LENGTH}
            required
            autoFocus
            aria-invalid={errors.password ? true : undefined}
          />
          {errors.password ? (
            <FieldError>{errors.password}</FieldError>
          ) : (
            <FieldDescription>{t("password.hint", { count: MIN_PASSWORD_LENGTH })}</FieldDescription>
          )}
        </Field>
        <Field data-invalid={errors.confirmation ? true : undefined}>
          <FieldLabel htmlFor="confirmation">{t("password.repeat")}</FieldLabel>
          <Input
            id="confirmation"
            name="confirmation"
            type="password"
            autoComplete="new-password"
            required
            aria-invalid={errors.confirmation ? true : undefined}
          />
          {errors.confirmation && <FieldError>{errors.confirmation}</FieldError>}
        </Field>
        {errors.form && (
          <Alert variant="destructive">
            <CircleAlertIcon />
            <AlertDescription className="text-pretty">{errors.form}</AlertDescription>
          </Alert>
        )}
        <Button type="submit" disabled={pending}>
          {pending ? t("common.saving") : t("password.set.submit")}
        </Button>
      </FieldGroup>
    </form>
  )
}

function InvalidLinkNotice({ requestNewLink }: { requestNewLink: ReactElement }) {
  const { t } = useTranslation()
  return (
    <Alert variant="destructive">
      <CircleAlertIcon />
      <AlertTitle>{t("password.set.invalidLinkTitle")}</AlertTitle>
      <AlertDescription className="text-pretty">
        <p>{t("password.set.invalidLinkReason")}</p>
        <p>
          <Trans t={t} i18nKey="password.set.invalidLinkNext" components={{ requestNewLink }} />
        </p>
      </AlertDescription>
    </Alert>
  )
}
