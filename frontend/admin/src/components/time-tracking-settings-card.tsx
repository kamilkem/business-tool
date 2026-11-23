import { useState, type FormEvent } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"

import { FORM_WIDTH } from "@/components/page-container"
import { api } from "@/lib/api"
import { timeTrackingSettingsQuery } from "@/lib/queries"
import {
  MAX_MAX_SHIFT_HOURS,
  MIN_MAX_SHIFT_HOURS,
  type TimeTrackingSettings,
  type TimeTrackingSettingsFormErrors,
  maxShiftHoursProblem,
  parseMaxShiftHours,
  timeTrackingSettingsFormErrors,
} from "@/lib/time-tracking-settings"

/** Time tracking (#33), on the organization settings page: the maximum shift length. */
export function TimeTrackingSettingsCard({ organizationId }: { organizationId: string }) {
  const { data: settings } = useSuspenseQuery(timeTrackingSettingsQuery(organizationId))
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("timeTrackingSettings.title")}</CardTitle>
        <CardDescription>
          {t("timeTrackingSettings.description")}{" "}
          {settings.updatedAt
            ? t("timeTrackingSettings.changedAt", { at: format.dateTime(settings.updatedAt) })
            : t("timeTrackingSettings.defaults")}
        </CardDescription>
      </CardHeader>
      <CardContent>
        {/* A new key per saved version, so the form starts from what the API has. */}
        <TimeTrackingSettingsForm key={settings.maxShiftHours} organizationId={organizationId} settings={settings} />
      </CardContent>
    </Card>
  )
}

function TimeTrackingSettingsForm({ organizationId, settings }: { organizationId: string; settings: TimeTrackingSettings }) {
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const [hours, setHours] = useState(String(settings.maxShiftHours))
  const [errors, setErrors] = useState<TimeTrackingSettingsFormErrors>({})

  const save = useMutation({
    mutationFn: (body: { maxShiftHours: number }) => unwrap(api.PUT("/api/time-tracking-settings", { body })),
    onSuccess: (saved) => {
      setErrors({})
      queryClient.setQueryData(timeTrackingSettingsQuery(organizationId).queryKey, saved)
      toast.success(t("timeTrackingSettings.saved"))
    },
    onError: (error) => setErrors(timeTrackingSettingsFormErrors(error)),
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const maxShiftHours = parseMaxShiftHours(hours)
    if (maxShiftHours === null) return setErrors({ maxShiftHours: maxShiftHoursProblem(hours) ?? undefined })
    setErrors({})
    save.mutate({ maxShiftHours })
  }

  const changed = parseMaxShiftHours(hours) !== settings.maxShiftHours

  return (
    <form onSubmit={onSubmit} className={FORM_WIDTH} noValidate>
      <fieldset disabled={save.isPending}>
        <FieldGroup>
          <Field data-invalid={errors.maxShiftHours ? true : undefined}>
            <FieldLabel htmlFor="max-shift-hours">{t("timeTrackingSettings.maxShiftHours")}</FieldLabel>
            <div className="flex items-center gap-2">
              <Input
                id="max-shift-hours"
                type="number"
                inputMode="numeric"
                className="w-24"
                min={MIN_MAX_SHIFT_HOURS}
                max={MAX_MAX_SHIFT_HOURS}
                step={1}
                value={hours}
                onChange={(event) => {
                  setHours(event.target.value)
                  if (errors.maxShiftHours) setErrors({ ...errors, maxShiftHours: undefined })
                }}
                aria-invalid={errors.maxShiftHours ? true : undefined}
                aria-describedby="max-shift-hours-unit"
                required
              />
              <span id="max-shift-hours-unit" className="text-sm text-muted-foreground">
                {t("timeTrackingSettings.hours")}
              </span>
            </div>
            {errors.maxShiftHours ? (
              <FieldError>{errors.maxShiftHours}</FieldError>
            ) : (
              <FieldDescription>
                {t("timeTrackingSettings.maxShiftHoursDescription", { min: MIN_MAX_SHIFT_HOURS, max: MAX_MAX_SHIFT_HOURS })}
              </FieldDescription>
            )}
          </Field>
          {errors.form && (
            <Field data-invalid>
              <FieldError>{errors.form}</FieldError>
            </Field>
          )}
          <Button type="submit" className="self-start" disabled={!changed || save.isPending}>
            {save.isPending ? t("common.saving", { ns: "ui" }) : t("common.saveChanges")}
          </Button>
        </FieldGroup>
      </fieldset>
    </form>
  )
}
