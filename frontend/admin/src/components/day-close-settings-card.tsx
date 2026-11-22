import { useState, type FormEvent } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"

import { FORM_WIDTH } from "@/components/page-container"
import { api } from "@/lib/api"
import {
  DAY_CLOSE_DATE_MODES,
  type DayCloseDateMode,
  type DayCloseSettings,
  type DayCloseSettingsFormErrors,
  LATEST_DAY_END_TIME,
  dayCloseSettingsChanged,
  dayCloseSettingsFormErrors,
  dayEndTimeProblem,
} from "@/lib/day-close-settings"
import { dayCloseSettingsQuery, invalidateDayCloseForm } from "@/lib/queries"

/**
 * Close of day (ADR 0033 §5), on the organization settings page: whether a close's date follows
 * from the day-end time or the employee picks it, and the day-end time itself.
 */
export function DayCloseSettingsCard({ organizationId }: { organizationId: string }) {
  const { data: settings } = useSuspenseQuery(dayCloseSettingsQuery(organizationId))
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("dayCloseSettings.title")}</CardTitle>
        <CardDescription>
          {t("dayCloseSettings.description")}{" "}
          {settings.updatedAt
            ? t("dayCloseSettings.changedAt", { at: format.dateTime(settings.updatedAt) })
            : t("dayCloseSettings.defaults")}
        </CardDescription>
      </CardHeader>
      <CardContent>
        {/* A new key per saved version, so the form starts from what the API has. */}
        <DayCloseSettingsForm key={`${settings.mode}-${settings.dayEndsAt}`} organizationId={organizationId} settings={settings} />
      </CardContent>
    </Card>
  )
}

function DayCloseSettingsForm({ organizationId, settings }: { organizationId: string; settings: DayCloseSettings }) {
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const [mode, setMode] = useState<DayCloseDateMode>(settings.mode)
  const [dayEndsAt, setDayEndsAt] = useState(settings.dayEndsAt)
  const [errors, setErrors] = useState<DayCloseSettingsFormErrors>({})

  const save = useMutation({
    mutationFn: (body: { mode: DayCloseDateMode; dayEndsAt: string }) => unwrap(api.PUT("/api/day-close-settings", { body })),
    onSuccess: async (saved) => {
      setErrors({})
      queryClient.setQueryData(dayCloseSettingsQuery(organizationId).queryKey, saved)
      await invalidateDayCloseForm(queryClient, organizationId)
      toast.success(t("dayCloseSettings.saved"))
    },
    onError: (error) => setErrors(dayCloseSettingsFormErrors(error)),
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const problem = dayEndTimeProblem(dayEndsAt)
    if (problem) return setErrors({ dayEndsAt: problem })
    setErrors({})
    save.mutate({ mode, dayEndsAt })
  }

  const changed = dayCloseSettingsChanged(settings, { mode, dayEndsAt })

  return (
    <form onSubmit={onSubmit} className={FORM_WIDTH} noValidate>
      <fieldset disabled={save.isPending}>
        <FieldGroup>
          <Field>
            <FieldLabel htmlFor="day-close-mode">{t("dayCloseSettings.modeLabel")}</FieldLabel>
            <Select value={mode} onValueChange={(value) => setMode(value as DayCloseDateMode)}>
              <SelectTrigger id="day-close-mode" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {DAY_CLOSE_DATE_MODES.map((value) => (
                  <SelectItem key={value} value={value}>
                    {t(`dayCloseSettings.mode.${value}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FieldDescription>{t(`dayCloseSettings.modeDescription.${mode}`)}</FieldDescription>
          </Field>
          <Field data-invalid={errors.dayEndsAt ? true : undefined}>
            <FieldLabel htmlFor="day-close-ends-at">{t("dayCloseSettings.dayEndsAt")}</FieldLabel>
            <Input
              id="day-close-ends-at"
              type="time"
              className="w-32"
              min="00:00"
              max={LATEST_DAY_END_TIME}
              step={60}
              value={dayEndsAt}
              onChange={(event) => {
                setDayEndsAt(event.target.value)
                // A refusal of the time no longer applies to the new one.
                if (errors.dayEndsAt) setErrors({ ...errors, dayEndsAt: undefined })
              }}
              aria-invalid={errors.dayEndsAt ? true : undefined}
              required
            />
            {errors.dayEndsAt ? (
              <FieldError>{errors.dayEndsAt}</FieldError>
            ) : (
              <FieldDescription>
                {t(`dayCloseSettings.dayEndsAtDescription.${mode}`, { time: dayEndsAt || "—", latest: LATEST_DAY_END_TIME })}
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
