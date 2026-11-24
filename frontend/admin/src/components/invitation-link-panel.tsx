import { useState } from "react"
import { type components, unwrap } from "@business-tool/api-client"
import { useMutation } from "@tanstack/react-query"
import { CopyIcon, LinkIcon } from "lucide-react"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Alert, AlertDescription } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Field, FieldDescription, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { api } from "@/lib/api"

type InvitationLink = components["schemas"]["InvitationLink"]

interface InvitationLinkPanelProps {
  /** The member whose invitation is pending. */
  userId: string
  /** Their full name, for the warning. */
  name: string
}

/**
 * Creates an invitation link for a member who has not set a password yet, for the owner or manager to
 * pass on themselves when the e-mail does not reach them (ADR 0037). The link is created on request,
 * because it replaces the e-mailed one, and kept in component state only: it carries a secret.
 */
export function InvitationLinkPanel({ userId, name }: InvitationLinkPanelProps) {
  const { t } = useTranslation("admin")
  const [link, setLink] = useState<InvitationLink | null>(null)

  const create = useMutation({
    mutationFn: () => unwrap(api.POST("/api/members/{userId}/invitation-link", { params: { path: { userId } } })),
    onSuccess: setLink,
    onError: (error) => toast.error(t("invitationLink.error"), { description: requestErrorMessage(error) }),
  })

  async function copy(value: string) {
    try {
      await navigator.clipboard.writeText(value)
      toast.success(t("invitationLink.copied"))
    } catch {
      toast.error(t("invitationLink.copyFailed"))
    }
  }

  if (link === null) {
    return (
      <div className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">{t("invitationLink.explain")}</p>
        <Button type="button" variant="outline" className="self-start" disabled={create.isPending} onClick={() => create.mutate()}>
          <LinkIcon />
          {create.isPending ? t("invitationLink.creating") : t("invitationLink.create")}
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-3">
      <Field>
        <FieldLabel htmlFor={`invitation-link-${userId}`}>{t("invitationLink.label")}</FieldLabel>
        <div className="flex gap-2">
          <Input
            id={`invitation-link-${userId}`}
            value={link.link}
            readOnly
            onFocus={(event) => event.currentTarget.select()}
            className="font-mono text-xs"
          />
          <Button type="button" onClick={() => copy(link.link)}>
            <CopyIcon />
            {t("invitationLink.copy")}
          </Button>
        </div>
        <FieldDescription>{t("invitationLink.validFor", { count: link.validForDays })}</FieldDescription>
      </Field>
      <Alert>
        <AlertDescription>{t("invitationLink.warning", { name })}</AlertDescription>
      </Alert>
    </div>
  )
}
