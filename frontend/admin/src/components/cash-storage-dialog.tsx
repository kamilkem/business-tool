import { useState, type FormEvent } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Button } from "@business-tool/ui/components/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@business-tool/ui/components/dialog"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"

import { api } from "@/lib/api"
import {
  CASH_STORAGE_MODES,
  CASH_STORAGE_NAME_MAX_LENGTH,
  type CashStorage,
  type CashStorageFormErrors,
  type CashStorageMode,
  cashStorageFormErrors,
  cashStoragePatch,
  withCashStorage,
} from "@/lib/cash-storages"
import { cashStoragesQuery, invalidateDayCloseForm } from "@/lib/queries"

interface CashStorageDialogProps {
  organizationId: string
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The storage to change; without one, the dialog adds a new storage. */
  storage?: CashStorage | null
}

/** Adds a cash storage, or changes one (active or deactivated): its name and whether it is counted or takes deposits. */
export function CashStorageDialog({ organizationId, open, onOpenChange, storage }: CashStorageDialogProps) {
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const [errors, setErrors] = useState<CashStorageFormErrors>({})
  const listKey = cashStoragesQuery(organizationId).queryKey
  const editing = storage ?? null

  const save = useMutation({
    mutationFn: ({ name, mode }: { name: string; mode: CashStorageMode }) =>
      editing
        ? unwrap(
            api.PATCH("/api/cash-storages/{id}", {
              params: { path: { id: editing.id } },
              body: cashStoragePatch(editing, { name, mode }),
              headers: { "Content-Type": "application/merge-patch+json" },
            }),
          )
        : unwrap(api.POST("/api/cash-storages", { body: { name, mode } })),
    onSuccess: async (saved) => {
      queryClient.setQueryData(listKey, (list) => (list ? withCashStorage(list, saved) : list))
      await invalidateDayCloseForm(queryClient, organizationId)
      toast.success(
        editing ? t("cashStorages.toast.saved", { name: saved.name }) : t("cashStorages.toast.added", { name: saved.name }),
      )
      close()
    },
    onError: (error, { name }) => setErrors(cashStorageFormErrors(error, name, queryClient.getQueryData(listKey) ?? [])),
  })

  function close() {
    setErrors({})
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
      <DialogContent className="sm:max-w-md">
        {/* The content mounts on each open, so the form starts from the storage's name and mode. */}
        <CashStorageForm
          editing={editing}
          errors={errors}
          pending={save.isPending}
          onSubmit={(values) => {
            setErrors({})
            save.mutate(values)
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

interface CashStorageFormProps {
  editing: CashStorage | null
  errors: CashStorageFormErrors
  pending: boolean
  onSubmit: (values: { name: string; mode: CashStorageMode }) => void
}

function CashStorageForm({ editing, errors, pending, onSubmit }: CashStorageFormProps) {
  const { t } = useTranslation("admin")
  const [mode, setMode] = useState<CashStorageMode>(editing?.mode ?? "counted")

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    onSubmit({
      name: String(new FormData(event.currentTarget).get("name")),
      mode,
    })
  }

  return (
    <form onSubmit={submit} noValidate>
      <DialogHeader>
        <DialogTitle>{editing ? t("cashStorages.dialog.editTitle", { name: editing.name }) : t("cashStorages.add")}</DialogTitle>
        <DialogDescription>
          {editing ? t("cashStorages.dialog.editDescription") : t("cashStorages.dialog.addDescription")}
        </DialogDescription>
      </DialogHeader>
      <fieldset disabled={pending} className="py-4">
        <FieldGroup>
          <Field data-invalid={errors.name ? true : undefined}>
            <FieldLabel htmlFor="cash-storage-name">{t("cashStorages.dialog.name")}</FieldLabel>
            <Input
              id="cash-storage-name"
              name="name"
              autoComplete="off"
              defaultValue={editing?.name ?? ""}
              maxLength={CASH_STORAGE_NAME_MAX_LENGTH}
              aria-invalid={errors.name ? true : undefined}
              required
            />
            {errors.name ? (
              <FieldError>{errors.name}</FieldError>
            ) : (
              <FieldDescription>
                {t("cashStorages.dialog.nameDescription", {
                  count: CASH_STORAGE_NAME_MAX_LENGTH,
                })}
              </FieldDescription>
            )}
          </Field>
          <Field data-invalid={errors.mode ? true : undefined}>
            <FieldLabel htmlFor="cash-storage-mode">{t("cashStorages.dialog.mode")}</FieldLabel>
            <Select value={mode} onValueChange={(value) => setMode(value as CashStorageMode)}>
              <SelectTrigger id="cash-storage-mode" className="w-full" aria-invalid={errors.mode ? true : undefined}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {CASH_STORAGE_MODES.map((value) => (
                  <SelectItem key={value} value={value}>
                    {t(`cashStorages.mode.${value}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {errors.mode ? (
              <FieldError>{errors.mode}</FieldError>
            ) : (
              <FieldDescription>
                {t(`cashStorages.modeDescription.${mode}`)}
                {editing && editing.mode !== mode && ` ${t("cashStorages.dialog.modeChangeHint")}`}
              </FieldDescription>
            )}
          </Field>
          {errors.form && (
            <Field data-invalid>
              <FieldError>{errors.form}</FieldError>
            </Field>
          )}
        </FieldGroup>
      </fieldset>
      <DialogFooter>
        <Button type="submit" disabled={pending}>
          {pending ? t("common.saving", { ns: "ui" }) : editing ? t("common.save") : t("cashStorages.add")}
        </Button>
      </DialogFooter>
    </form>
  )
}
