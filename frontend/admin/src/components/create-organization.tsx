import { useState, type FormEvent } from "react"
import { unwrap } from "@business-tool/api-client"
import { useRouteContext, useRouter } from "@tanstack/react-router"

import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"

import { api } from "@/lib/api"
import { refetchMe } from "@/lib/queries"

/** Shown to signed-in users who don't belong to any organization yet. */
export function CreateOrganization() {
  const { session, queryClient } = useRouteContext({ from: "/_app" })
  const router = useRouter()
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setPending(true)
    setError(null)
    try {
      const organization = await unwrap(
        api.POST("/api/organizations", {
          body: { name: String(form.get("name")), taxId: String(form.get("taxId")) || null },
        }),
      )
      session.selectOrganization(organization.id)
      await refetchMe(queryClient)
      await router.invalidate()
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not create the organization")
    } finally {
      setPending(false)
    }
  }

  return (
    <div className="flex min-h-svh items-center justify-center bg-muted p-4">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle className="text-xl">Set up your business</CardTitle>
          <CardDescription>You don't belong to any organization yet. Create one to get started.</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={onSubmit}>
            <FieldGroup>
              <Field>
                <FieldLabel htmlFor="name">Business name</FieldLabel>
                <Input id="name" name="name" required />
              </Field>
              <Field data-invalid={error ? true : undefined}>
                <FieldLabel htmlFor="taxId">Tax ID (NIP)</FieldLabel>
                <Input id="taxId" name="taxId" inputMode="numeric" />
                <FieldDescription>Optional for now. Needed later to fetch invoices from KSeF.</FieldDescription>
                {error && <FieldError>{error}</FieldError>}
              </Field>
              <Button type="submit" disabled={pending}>
                {pending ? "Creating…" : "Create organization"}
              </Button>
            </FieldGroup>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
