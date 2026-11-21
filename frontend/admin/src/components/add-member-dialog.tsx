import { useState, type FormEvent } from "react"
import { ApiError, type components, unwrap, violationsOf } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { UserPlusIcon } from "lucide-react"
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
  DialogTrigger,
} from "@business-tool/ui/components/dialog"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { LanguageSelect } from "@business-tool/ui/components/language-select"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { currentLanguage } from "@business-tool/ui/i18n/current-language"
import type { Language } from "@business-tool/ui/i18n/languages"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { api } from "@/lib/api"
import { type Role, assignableRoles, fullName, isActive, roleLabel } from "@/lib/members"
import { membersQuery } from "@/lib/queries"

type AddMemberInput = components["schemas"]["Member.AddMemberInput"]

interface AddMemberDialogProps {
  organizationId: string
  actorId: string
  actorRole: Role
}

export function AddMemberDialog({ organizationId, actorId, actorRole }: AddMemberDialogProps) {
  const { t } = useTranslation("admin")
  const queryClient = useQueryClient()
  const roles = assignableRoles(actorRole)
  const [open, setOpen] = useState(false)
  const [role, setRole] = useState<Role>("employee")
  // A new person's language defaults to the inviter's (ADR 0028 §6).
  const [language, setLanguage] = useState<Language>(currentLanguage)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)

  function reset() {
    setRole("employee")
    setLanguage(currentLanguage())
    setFieldErrors({})
    setFormError(null)
  }

  /** A 409 means the e-mail already belongs to a member: say whether they are active or deactivated. */
  async function conflictMessage(email: string, fallback: string): Promise<string> {
    const all = await queryClient.fetchQuery(membersQuery(organizationId, true)).catch(() => [])
    const existing = all.find((m) => m.email.toLowerCase() === email.trim().toLowerCase())
    if (!existing) return fallback
    return isActive(existing)
      ? `${fullName(existing)} (${existing.email}) is already a member of this organization.`
      : `${fullName(existing)} (${existing.email}) is a deactivated member. Show deactivated members and reactivate them instead.`
  }

  const add = useMutation({
    mutationFn: (body: AddMemberInput) => unwrap(api.POST("/api/members", { body })),
    onSuccess: async (member) => {
      await queryClient.invalidateQueries({ queryKey: ["members", organizationId] })
      toast.success(
        member.invitationPending
          ? `Invitation sent to ${member.email}`
          : `${fullName(member)} added as ${roleLabel(member.role).toLowerCase()}`,
      )
      setOpen(false)
      reset()
    },
    onError: async (error, body) => {
      setFieldErrors(violationsOf(error))
      const message = requestErrorMessage(error, { selfId: actorId })
      if (error instanceof ApiError && error.status === 422) {
        setFormError(null)
      } else if (error instanceof ApiError && error.status === 409) {
        setFormError(await conflictMessage(body.email, message))
      } else {
        setFormError(message)
      }
    },
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setFieldErrors({})
    setFormError(null)
    add.mutate({
      email: String(form.get("email")),
      firstName: String(form.get("firstName")),
      lastName: String(form.get("lastName")),
      role,
      language,
    })
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        if (!next) reset()
      }}
    >
      <DialogTrigger asChild>
        <Button>
          <UserPlusIcon />
          Add member
        </Button>
      </DialogTrigger>
      <DialogContent className="sm:max-w-md">
        <form onSubmit={onSubmit} noValidate>
          <DialogHeader>
            <DialogTitle>Add member</DialogTitle>
            <DialogDescription>
              New people get an e-mail with a link to set their password. People who already have an account keep
              their own name and password.
            </DialogDescription>
          </DialogHeader>
          <fieldset disabled={add.isPending} className="py-4">
            <FieldGroup>
              <Field data-invalid={fieldErrors.email ? true : undefined}>
                <FieldLabel htmlFor="member-email">E-mail</FieldLabel>
                <Input
                  id="member-email"
                  name="email"
                  type="email"
                  autoComplete="off"
                  aria-invalid={fieldErrors.email ? true : undefined}
                  required
                />
                {fieldErrors.email && <FieldError>{fieldErrors.email}</FieldError>}
              </Field>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field data-invalid={fieldErrors.firstName ? true : undefined}>
                  <FieldLabel htmlFor="member-first-name">First name</FieldLabel>
                  <Input
                    id="member-first-name"
                    name="firstName"
                    autoComplete="off"
                    aria-invalid={fieldErrors.firstName ? true : undefined}
                    required
                  />
                  {fieldErrors.firstName && <FieldError>{fieldErrors.firstName}</FieldError>}
                </Field>
                <Field data-invalid={fieldErrors.lastName ? true : undefined}>
                  <FieldLabel htmlFor="member-last-name">Last name</FieldLabel>
                  <Input
                    id="member-last-name"
                    name="lastName"
                    autoComplete="off"
                    aria-invalid={fieldErrors.lastName ? true : undefined}
                    required
                  />
                  {fieldErrors.lastName && <FieldError>{fieldErrors.lastName}</FieldError>}
                </Field>
              </div>
              <Field data-invalid={fieldErrors.role ? true : undefined}>
                <FieldLabel htmlFor="member-role">Role</FieldLabel>
                <Select value={role} onValueChange={(value) => setRole(value as Role)} disabled={roles.length < 2}>
                  <SelectTrigger id="member-role" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {roles.map((r) => (
                      <SelectItem key={r} value={r}>
                        {roleLabel(r)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {roles.length < 2 && <FieldDescription>Managers add employees only.</FieldDescription>}
                {fieldErrors.role && <FieldError>{fieldErrors.role}</FieldError>}
              </Field>
              <Field data-invalid={fieldErrors.language ? true : undefined}>
                <FieldLabel htmlFor="member-language">{t("addMember.language.label")}</FieldLabel>
                <LanguageSelect id="member-language" value={language} onValueChange={setLanguage} />
                <FieldDescription>{t("addMember.language.description")}</FieldDescription>
                {fieldErrors.language && <FieldError>{fieldErrors.language}</FieldError>}
              </Field>
              {formError && (
                <Field data-invalid>
                  <FieldError>{formError}</FieldError>
                </Field>
              )}
            </FieldGroup>
          </fieldset>
          <DialogFooter>
            <Button type="submit" disabled={add.isPending}>
              {add.isPending ? "Adding…" : "Add member"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
