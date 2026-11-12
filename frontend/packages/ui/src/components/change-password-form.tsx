import { useRef, useState, type FormEvent } from "react"
import { type ApiClient, unwrap, violationsOf } from "@business-tool/api-client"
import { CircleAlertIcon, CircleCheckIcon } from "lucide-react"

import { Alert, AlertDescription, AlertTitle } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"
import { cn } from "@business-tool/ui/lib/utils"

/** Mirrors the API's rule (at least 8 characters, no composition rules); the API has the last word. */
const MIN_PASSWORD_LENGTH = 8

interface Errors {
  currentPassword?: string
  newPassword?: string
  confirmation?: string
  form?: string
}

export interface ChangePasswordFormProps {
  api: ApiClient
  /** The signed-in person's e-mail, so password managers know which account the new password is for. */
  email: string
  /** Taller fields and a full-width button, for the timeclock's touch screens. */
  touch?: boolean
}

/**
 * Changes the signed-in person's password (`POST /api/me/password`, #12).
 * The API ends every other session and keeps this one, so the person stays
 * signed in here; a 401 is handled by the API client (it signs out).
 */
export function ChangePasswordForm({ api, email, touch = false }: ChangePasswordFormProps) {
  const formRef = useRef<HTMLFormElement>(null)
  const [errors, setErrors] = useState<Errors>({})
  const [pending, setPending] = useState(false)
  const [changed, setChanged] = useState(false)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const currentPassword = String(form.get("currentPassword") ?? "")
    const newPassword = String(form.get("newPassword") ?? "")
    const confirmation = String(form.get("confirmation") ?? "")

    const local: Errors = {}
    if (currentPassword === "") local.currentPassword = "Enter your current password."
    if (newPassword.length < MIN_PASSWORD_LENGTH) {
      local.newPassword = `Use at least ${MIN_PASSWORD_LENGTH} characters.`
    } else if (confirmation !== newPassword) {
      local.confirmation = "The passwords don't match."
    }
    setErrors(local)
    setChanged(false)
    if (Object.keys(local).length > 0) return

    setPending(true)
    try {
      await unwrap(api.POST("/api/me/password", { body: { currentPassword, newPassword } }))
      formRef.current?.reset()
      setChanged(true)
    } catch (error) {
      const violations = violationsOf(error)
      if (violations.currentPassword || violations.newPassword) {
        setErrors({ currentPassword: violations.currentPassword, newPassword: violations.newPassword })
      } else {
        setErrors({ form: Object.values(violations)[0] ?? requestErrorMessage(error) })
      }
    } finally {
      setPending(false)
    }
  }

  const inputClass = cn(touch && "h-11")

  return (
    <form ref={formRef} onSubmit={onSubmit} noValidate>
      <input type="text" name="username" autoComplete="username" value={email} readOnly hidden />
      <FieldGroup>
        <Field data-invalid={errors.currentPassword ? true : undefined}>
          <FieldLabel htmlFor="currentPassword">Current password</FieldLabel>
          <Input
            id="currentPassword"
            name="currentPassword"
            type="password"
            autoComplete="current-password"
            required
            className={inputClass}
            aria-invalid={errors.currentPassword ? true : undefined}
          />
          {errors.currentPassword && <FieldError>{errors.currentPassword}</FieldError>}
        </Field>
        <Field data-invalid={errors.newPassword ? true : undefined}>
          <FieldLabel htmlFor="newPassword">New password</FieldLabel>
          <Input
            id="newPassword"
            name="newPassword"
            type="password"
            autoComplete="new-password"
            minLength={MIN_PASSWORD_LENGTH}
            required
            className={inputClass}
            aria-invalid={errors.newPassword ? true : undefined}
          />
          {errors.newPassword ? (
            <FieldError>{errors.newPassword}</FieldError>
          ) : (
            <FieldDescription>At least {MIN_PASSWORD_LENGTH} characters.</FieldDescription>
          )}
        </Field>
        <Field data-invalid={errors.confirmation ? true : undefined}>
          <FieldLabel htmlFor="confirmation">Repeat the new password</FieldLabel>
          <Input
            id="confirmation"
            name="confirmation"
            type="password"
            autoComplete="new-password"
            required
            className={inputClass}
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
        {changed && (
          <Alert>
            <CircleCheckIcon />
            <AlertTitle>Your password is changed</AlertTitle>
            <AlertDescription className="text-pretty">
              You stay signed in here. On your other devices, sign in again with the new password.
            </AlertDescription>
          </Alert>
        )}
        <Button type="submit" disabled={pending} className={cn(touch ? "h-11 w-full" : "self-start")}>
          {pending ? "Saving…" : "Change password"}
        </Button>
      </FieldGroup>
    </form>
  )
}
