import { useState, type FormEvent } from "react"
import { login } from "@business-tool/api-client"
import { Link, createFileRoute, redirect, useNavigate } from "@tanstack/react-router"
import { CircleCheckIcon } from "lucide-react"

import { Alert, AlertDescription } from "@business-tool/ui/components/alert"
import { AuthScreen } from "@business-tool/ui/components/auth-screen"
import { Button } from "@business-tool/ui/components/button"
import { Field, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"

export const Route = createFileRoute("/login")({
  validateSearch: (search: Record<string, unknown>): { redirect?: string; passwordSet?: boolean } => ({
    redirect: typeof search.redirect === "string" ? search.redirect : undefined,
    // Set by /set-password after the API accepted the new password.
    passwordSet: search.passwordSet === true ? true : undefined,
  }),
  beforeLoad: ({ context }) => {
    if (context.session.get().token) throw redirect({ to: "/" })
  },
  component: LoginPage,
})

function LoginPage() {
  const { session, queryClient } = Route.useRouteContext()
  const { redirect: redirectTo, passwordSet } = Route.useSearch()
  const navigate = useNavigate()
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setPending(true)
    setError(null)
    try {
      const token = await login(String(form.get("email")), String(form.get("password")))
      queryClient.clear()
      session.signIn(token)
      await navigate({ to: redirectTo?.startsWith("/") ? redirectTo : "/" })
    } catch (e) {
      setError(e instanceof Error ? e.message : "Login failed")
    } finally {
      setPending(false)
    }
  }

  return (
    <AuthScreen title="Timeclock" description="Sign in to log your shifts">
      <form onSubmit={onSubmit}>
        <FieldGroup>
          {passwordSet && (
            <Alert>
              <CircleCheckIcon />
              <AlertDescription>Your password is set. Sign in with it.</AlertDescription>
            </Alert>
          )}
          <Field>
            <FieldLabel htmlFor="email">Email</FieldLabel>
            <Input id="email" name="email" type="email" autoComplete="username" required />
          </Field>
          <Field data-invalid={error ? true : undefined}>
            <div className="flex items-center">
              <FieldLabel htmlFor="password">Password</FieldLabel>
              <Link to="/forgot-password" className="ml-auto text-sm underline-offset-4 hover:underline">
                Forgot password?
              </Link>
            </div>
            <Input id="password" name="password" type="password" autoComplete="current-password" required />
            {error && <FieldError>{error}</FieldError>}
          </Field>
          <Button type="submit" disabled={pending}>
            {pending ? "Signing in…" : "Sign in"}
          </Button>
        </FieldGroup>
      </form>
    </AuthScreen>
  )
}
