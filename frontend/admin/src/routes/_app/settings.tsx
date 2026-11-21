import { useState, type FormEvent } from "react"
import { type components, unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute, useRouter } from "@tanstack/react-router"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { KsefConnectionCard } from "@/components/ksef-connection-card"
import { api } from "@/lib/api"
import { can, requireCapability } from "@/lib/access"
import { ksefAvailableIn } from "@/lib/ksef"
import { organizationQuery, refetchMe } from "@/lib/queries"

type OrganizationPatch = components["schemas"]["Organization.UpdateOrganizationInput.jsonMergePatch"]
type CsvSeparator = components["schemas"]["Organization"]["csvSeparator"]

/** The separators, in the order the form offers them; their texts are `settings.csvSeparator.<value>`. */
const CSV_SEPARATORS: readonly CsvSeparator[] = ["semicolon", "comma"]

export const Route = createFileRoute("/_app/settings")({
  beforeLoad: requireCapability("settings"),
  loader: ({ context }) => context.queryClient.ensureQueryData(organizationQuery(context.membership.organization.id)),
  component: SettingsPage,
})

function SettingsPage() {
  const { membership } = Route.useRouteContext()
  const id = membership.organization.id
  const { data: organization } = useSuspenseQuery(organizationQuery(id))
  const queryClient = useQueryClient()
  const router = useRouter()
  const [error, setError] = useState<string | null>(null)
  const { t } = useTranslation("admin")

  const save = useMutation({
    mutationFn: (body: OrganizationPatch) =>
      unwrap(
        api.PATCH("/api/organizations/{id}", {
          params: { path: { id } },
          body,
          headers: { "Content-Type": "application/merge-patch+json" },
        }),
      ),
    onSuccess: async (data) => {
      setError(null)
      queryClient.setQueryData(organizationQuery(id).queryKey, data)
      // The organization's name also lives in /me (sidebar and header).
      await refetchMe(queryClient)
      await router.invalidate()
      toast.success(t("settings.saved"))
    },
    onError: (e) => setError(requestErrorMessage(e)),
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    save.mutate({
      name: String(form.get("name")),
      taxId: String(form.get("taxId")) || null,
      countryCode: String(form.get("countryCode")),
      currency: String(form.get("currency")),
      timezone: String(form.get("timezone")),
      csvSeparator: String(form.get("csvSeparator")) as CsvSeparator,
    })
  }

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-6">
      <Card>
        <CardHeader>
          <CardTitle>{t("settings.title")}</CardTitle>
          <CardDescription>{t("settings.description")}</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={onSubmit}>
            <fieldset disabled={save.isPending}>
              <FieldGroup>
                <Field>
                  <FieldLabel htmlFor="name">{t("settings.name")}</FieldLabel>
                  <Input id="name" name="name" defaultValue={organization.name} required />
                </Field>
                <Field>
                  <FieldLabel htmlFor="taxId">{t("settings.taxId")}</FieldLabel>
                  <Input id="taxId" name="taxId" defaultValue={organization.taxId ?? ""} inputMode="numeric" />
                  <FieldDescription>{t("settings.taxIdDescription")}</FieldDescription>
                </Field>
                <div className="grid gap-4 sm:grid-cols-3">
                  <Field>
                    <FieldLabel htmlFor="countryCode">{t("settings.country")}</FieldLabel>
                    <Input id="countryCode" name="countryCode" defaultValue={organization.countryCode} maxLength={2} />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="currency">{t("settings.currency")}</FieldLabel>
                    <Input id="currency" name="currency" defaultValue={organization.currency} maxLength={3} />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="timezone">{t("settings.timeZone")}</FieldLabel>
                    <Input id="timezone" name="timezone" defaultValue={organization.timezone} />
                  </Field>
                </div>
                <Field>
                  <FieldLabel htmlFor="csvSeparator">{t("settings.csvExports")}</FieldLabel>
                  <Select name="csvSeparator" defaultValue={organization.csvSeparator}>
                    <SelectTrigger id="csvSeparator" className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {CSV_SEPARATORS.map((value) => (
                        <SelectItem key={value} value={value}>
                          {t(`settings.csvSeparator.${value}`)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FieldDescription>{t("settings.csvDescription")}</FieldDescription>
                </Field>
                <Field data-invalid={error ? true : undefined}>
                  {error && <FieldError>{error}</FieldError>}
                </Field>
                <Button type="submit" className="self-start">
                  {save.isPending ? t("common.saving", { ns: "ui" }) : t("common.saveChanges")}
                </Button>
              </FieldGroup>
            </fieldset>
          </form>
        </CardContent>
      </Card>
      {ksefAvailableIn(organization.countryCode) && can(membership.role, "ksef") && (
        <KsefConnectionCard membership={membership} />
      )}
    </div>
  )
}
