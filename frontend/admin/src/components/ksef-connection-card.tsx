import { useState, type FormEvent } from "react"
import { unwrap, violationsOf } from "@business-tool/api-client"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { toast } from "sonner"

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@business-tool/ui/components/alert-dialog"
import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { cn } from "@business-tool/ui/lib/utils"

import { api } from "@/lib/api"
import { type Membership, can } from "@/lib/access"
import { type KsefConnection, ksefErrorLabel, ksefState } from "@/lib/ksef"
import { ksefConnectionQuery } from "@/lib/queries"

const toneClass = {
  ok: "bg-emerald-600 text-white dark:bg-emerald-700",
  warning: "bg-amber-500 text-white dark:bg-amber-600",
  error: "",
  muted: "",
} as const

/**
 * The KSeF section of the settings (#49): whether the token works, and for owners the form to save
 * or remove it. The token is write-only: the API never returns it, and the field is cleared once
 * it is saved. Rendered for organizations in Poland only.
 */
export function KsefConnectionCard({ membership }: { membership: Membership }) {
  const organizationId = membership.organization.id
  const query = ksefConnectionQuery(organizationId)
  const { data: connection, isPending, error: loadError } = useQuery(query)
  const queryClient = useQueryClient()
  const format = useDateTimeFormat()
  const mayManageToken = can(membership.role, "ksefToken")
  const [token, setToken] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [confirmRemove, setConfirmRemove] = useState(false)

  const save = useMutation({
    mutationFn: (value: string) => unwrap(api.POST("/api/ksef-connection/token", { body: { token: value } })),
    onSuccess: (data) => {
      setToken("")
      setError(null)
      queryClient.setQueryData(query.queryKey, data)
      if (data.status === "active" && !data.lastCheckError) toast.success("KSeF token saved and accepted")
      else toast.warning("KSeF token saved, but the check failed")
    },
    onError: (e) => setError(violationsOf(e).token ?? e.message),
  })

  const test = useMutation({
    mutationFn: () => unwrap(api.POST("/api/ksef-connection/test")),
    onSuccess: (data) => {
      queryClient.setQueryData(query.queryKey, data)
      if (data.lastCheckError) toast.error(ksefErrorLabel(data.lastCheckError))
      else toast.success("KSeF accepted the token")
    },
    onError: (e) => toast.error(e.message),
  })

  const remove = useMutation({
    mutationFn: () => unwrap(api.DELETE("/api/ksef-connection/token")),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: query.queryKey })
      toast.success("KSeF token removed")
    },
    onError: (e) => toast.error(e.message),
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    save.mutate(token)
  }

  const state = ksefState(connection ?? null)
  const busy = save.isPending || test.isPending || remove.isPending

  return (
    <Card>
      <CardHeader>
        <CardTitle>KSeF</CardTitle>
        <CardDescription>
          Purchase invoices are imported from KSeF with a KSeF token generated for the organization's NIP with the
          InvoiceRead permission only.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-6">
        {loadError ? (
          <p className="text-destructive text-sm">{loadError.message}</p>
        ) : isPending ? (
          <p className="text-muted-foreground text-sm">Loading…</p>
        ) : (
          <KsefStatus connection={connection ?? null} state={state} format={format} />
        )}

        {connection?.tokenSet && (
          <Button variant="outline" className="self-start" disabled={busy} onClick={() => test.mutate()}>
            {test.isPending ? "Testing…" : "Test connection"}
          </Button>
        )}

        {mayManageToken && (
          <form onSubmit={onSubmit}>
            <fieldset disabled={busy}>
              <FieldGroup>
                <Field data-invalid={error ? true : undefined}>
                  <FieldLabel htmlFor="ksef-token">{connection?.tokenSet ? "Replace the KSeF token" : "KSeF token"}</FieldLabel>
                  <Input
                    id="ksef-token"
                    name="ksef-token"
                    type="password"
                    autoComplete="off"
                    spellCheck={false}
                    value={token}
                    onChange={(e) => setToken(e.target.value)}
                    aria-invalid={error ? true : undefined}
                  />
                  <FieldDescription>
                    Stored encrypted and never shown again. Saving checks it against KSeF right away.
                  </FieldDescription>
                  {error && <FieldError>{error}</FieldError>}
                </Field>
                <div className="flex flex-wrap gap-2">
                  <Button type="submit" disabled={token.trim() === ""}>
                    {save.isPending ? "Saving and checking…" : "Save and check"}
                  </Button>
                  {connection?.tokenSet && (
                    <Button type="button" variant="destructive" onClick={() => setConfirmRemove(true)}>
                      Remove token
                    </Button>
                  )}
                </div>
              </FieldGroup>
            </fieldset>
          </form>
        )}

        <AlertDialog open={confirmRemove} onOpenChange={setConfirmRemove}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Remove the KSeF token?</AlertDialogTitle>
              <AlertDialogDescription>
                Purchase invoices stop being imported until a new token is saved. Invoices already imported stay.
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>Cancel</AlertDialogCancel>
              <AlertDialogAction variant="destructive" onClick={() => remove.mutate()}>
                Remove
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </CardContent>
    </Card>
  )
}

function KsefStatus({
  connection,
  state,
  format,
}: {
  connection: KsefConnection | null
  state: ReturnType<typeof ksefState>
  format: ReturnType<typeof useDateTimeFormat>
}) {
  return (
    <div className="flex flex-col gap-2 text-sm">
      <div className="flex items-center gap-2">
        <Badge variant={state.tone === "error" ? "destructive" : state.tone === "muted" ? "secondary" : "default"} className={cn(toneClass[state.tone])}>
          {state.label}
        </Badge>
        {connection?.nip && <span className="text-muted-foreground">NIP {connection.nip}</span>}
      </div>
      {state.hint && <p className="text-muted-foreground">{state.hint}</p>}
      {connection?.tokenSet && (
        <dl className="text-muted-foreground grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
          {connection.tokenSetAt && (
            <>
              <dt>Token saved</dt>
              <dd>{format.dateTime(connection.tokenSetAt)}</dd>
            </>
          )}
          <dt>Last accepted by KSeF</dt>
          <dd>{connection.verifiedAt ? format.dateTime(connection.verifiedAt) : "Not yet"}</dd>
          {connection.lastCheckedAt && connection.lastCheckError && (
            <>
              <dt>Last check failed</dt>
              <dd>
                {format.dateTime(connection.lastCheckedAt)}: {connection.lastCheckErrorMessage ?? ksefErrorLabel(connection.lastCheckError)}
              </dd>
            </>
          )}
        </dl>
      )}
    </div>
  )
}
