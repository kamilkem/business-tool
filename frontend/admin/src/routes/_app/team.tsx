import { useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute, useNavigate, useRouter } from "@tanstack/react-router"
import { LinkIcon, MailIcon, MoreHorizontalIcon, UserCheckIcon, UserXIcon } from "lucide-react"
import { useTranslation } from "react-i18next"
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
import { Card, CardContent } from "@business-tool/ui/components/card"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@business-tool/ui/components/dialog"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@business-tool/ui/components/dropdown-menu"
import { Label } from "@business-tool/ui/components/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { Switch } from "@business-tool/ui/components/switch"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@business-tool/ui/components/table"
import { Tooltip, TooltipContent, TooltipTrigger } from "@business-tool/ui/components/tooltip"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { AddMemberDialog } from "@/components/add-member-dialog"
import { InvitationLinkPanel } from "@/components/invitation-link-panel"
import { api } from "@/lib/api"
import {
  type Member,
  type MemberStatus,
  type Role,
  assignableRoles,
  canActOn,
  fullName,
  isActive,
  isLastActiveOwner,
  memberNames,
  roleLabel,
  statusOf,
} from "@/lib/members"
import { requireCapability } from "@/lib/access"
import { membersQuery, refetchMe } from "@/lib/queries"

export const Route = createFileRoute("/_app/team")({
  validateSearch: (search: Record<string, unknown>): { deactivated?: boolean } => ({
    deactivated: search.deactivated === true || search.deactivated === "true" ? true : undefined,
  }),
  beforeLoad: requireCapability("team"),
  loaderDeps: ({ search }) => ({ deactivated: search.deactivated ?? false }),
  loader: ({ context, deps }) =>
    context.queryClient.ensureQueryData(membersQuery(context.membership.organization.id, deps.deactivated)),
  component: TeamPage,
})

function TeamPage() {
  const { membership } = Route.useRouteContext()

  return <TeamTable organizationId={membership.organization.id} actorRole={membership.role} />
}

type Pending = { kind: "deactivate"; member: Member } | { kind: "role"; member: Member; role: Role }

function TeamTable({ organizationId, actorRole }: { organizationId: string; actorRole: Role }) {
  const { me } = Route.useRouteContext()
  const { deactivated = false } = Route.useSearch()
  const navigate = useNavigate({ from: Route.fullPath })
  const router = useRouter()
  const queryClient = useQueryClient()
  const { data: members } = useSuspenseQuery(membersQuery(organizationId, deactivated))
  const [pending, setPending] = useState<Pending | null>(null)
  /** The member whose invitation link is being created (ADR 0037). */
  const [linkFor, setLinkFor] = useState<Member | null>(null)
  const { t } = useTranslation("admin")

  /** After any change: refresh the list, and the signed-in user's own access when they changed themselves. */
  async function refresh(member: Member) {
    // Own access first: after stepping down to employee (or deactivating oneself) the page
    // stops showing the list, so it doesn't refetch one the API would now refuse.
    if (member.userId === me.id) {
      await refetchMe(queryClient)
      await router.invalidate()
    }
    await queryClient.invalidateQueries({ queryKey: ["members", organizationId] })
  }

  /** The API refused: show why, and reload the list, which was probably out of date. */
  function onError(title: string) {
    return async (error: Error) => {
      toast.error(title, { description: requestErrorMessage(error, { names: memberNames(members), selfId: me.id }) })
      await queryClient.invalidateQueries({ queryKey: ["members", organizationId] })
    }
  }

  const changeRole = useMutation({
    mutationFn: ({ member, role }: { member: Member; role: Role }) =>
      unwrap(
        api.PATCH("/api/members/{userId}", {
          params: { path: { userId: member.userId } },
          body: { role },
          headers: { "Content-Type": "application/merge-patch+json" },
        }),
      ),
    onSuccess: async (updated, { member }) => {
      toast.success(t(`team.toast.roleChanged.${updated.role}`, { name: fullName(updated) }))
      await refresh(member)
    },
    onError: onError(t("team.error.changeRole")),
  })

  const resendInvitation = useMutation({
    mutationFn: (member: Member) =>
      unwrap(api.POST("/api/members/{userId}/invitation", { params: { path: { userId: member.userId } } })),
    onSuccess: async (_, member) => {
      toast.success(t("team.toast.invitationResent", { email: member.email }))
      await refresh(member)
    },
    onError: onError(t("team.error.resendInvitation")),
  })

  const deactivate = useMutation({
    mutationFn: (member: Member) =>
      unwrap(api.POST("/api/members/{userId}/deactivate", { params: { path: { userId: member.userId } } })),
    onSuccess: async (_, member) => {
      toast.success(t("team.toast.deactivated", { name: fullName(member) }))
      await refresh(member)
    },
    onError: onError(t("team.error.deactivate")),
  })

  const reactivate = useMutation({
    mutationFn: (member: Member) =>
      unwrap(api.POST("/api/members/{userId}/reactivate", { params: { path: { userId: member.userId } } })),
    onSuccess: async (_, member) => {
      toast.success(t("team.toast.reactivated", { name: fullName(member) }))
      await refresh(member)
    },
    onError: onError(t("team.error.reactivate")),
  })

  function requestRoleChange(member: Member, role: Role) {
    if (role === member.role) return
    // Stepping down can remove one's own access to this page: confirm it first.
    if (member.userId === me.id) {
      setPending({ kind: "role", member, role })
    } else {
      changeRole.mutate({ member, role })
    }
  }

  function confirm() {
    if (!pending) return
    if (pending.kind === "deactivate") deactivate.mutate(pending.member)
    else changeRole.mutate({ member: pending.member, role: pending.role })
    setPending(null)
  }

  const busy = (member: Member) =>
    (changeRole.isPending && changeRole.variables.member.userId === member.userId) ||
    [resendInvitation, deactivate, reactivate].some((m) => m.isPending && m.variables.userId === member.userId)

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{t("team.title")}</h1>
          <p className="text-sm text-muted-foreground">
            {actorRole === "owner" ? t("team.description") : t("team.descriptionManager")}
          </p>
        </div>
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-2">
            <Switch
              id="show-deactivated"
              checked={deactivated}
              onCheckedChange={(checked) => navigate({ search: { deactivated: checked || undefined } })}
            />
            <Label htmlFor="show-deactivated">{t("team.showDeactivated")}</Label>
          </div>
          <AddMemberDialog organizationId={organizationId} actorId={me.id} actorRole={actorRole} />
        </div>
      </div>

      <Card className="py-0">
        <CardContent className="px-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-4">{t("team.column.name")}</TableHead>
                <TableHead>{t("common.email", { ns: "ui" })}</TableHead>
                <TableHead>{t("team.column.role")}</TableHead>
                <TableHead>{t("team.column.status")}</TableHead>
                <TableHead>{t("team.column.joined")}</TableHead>
                <TableHead className="pr-4">
                  <span className="sr-only">{t("common.actions")}</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {members.map((member) => (
                <MemberRow
                  key={member.userId}
                  member={member}
                  isSelf={member.userId === me.id}
                  actorRole={actorRole}
                  lastOwner={isLastActiveOwner(member, members)}
                  busy={busy(member)}
                  onRoleChange={(role) => requestRoleChange(member, role)}
                  onResend={() => resendInvitation.mutate(member)}
                  onInvitationLink={() => setLinkFor(member)}
                  onDeactivate={() => setPending({ kind: "deactivate", member })}
                  onReactivate={() => reactivate.mutate(member)}
                />
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Dialog open={linkFor !== null} onOpenChange={(open) => !open && setLinkFor(null)}>
        <DialogContent className="sm:max-w-lg">
          {linkFor && (
            <>
              <DialogHeader>
                <DialogTitle>{t("invitationLink.title", { name: fullName(linkFor) })}</DialogTitle>
                <DialogDescription>{linkFor.email}</DialogDescription>
              </DialogHeader>
              <InvitationLinkPanel userId={linkFor.userId} name={fullName(linkFor)} />
            </>
          )}
        </DialogContent>
      </Dialog>

      <AlertDialog open={pending !== null} onOpenChange={(open) => !open && setPending(null)}>
        <AlertDialogContent>
          {pending && <ConfirmText pending={pending} isSelf={pending.member.userId === me.id} />}
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction variant={pending?.kind === "deactivate" ? "destructive" : "default"} onClick={confirm}>
              {pending?.kind === "deactivate" ? t("team.deactivate") : t("team.confirm.changeRole")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

function ConfirmText({ pending, isSelf }: { pending: Pending; isSelf: boolean }) {
  const { t } = useTranslation("admin")
  const name = fullName(pending.member)
  if (pending.kind === "deactivate") {
    return (
      <AlertDialogHeader>
        <AlertDialogTitle>{isSelf ? t("team.confirm.deactivateSelfTitle") : t("team.confirm.deactivateTitle", { name })}</AlertDialogTitle>
        <AlertDialogDescription>
          {isSelf ? t("team.confirm.deactivateSelfDescription") : t("team.confirm.deactivateDescription")}
        </AlertDialogDescription>
      </AlertDialogHeader>
    )
  }
  return (
    <AlertDialogHeader>
      <AlertDialogTitle>{t(`team.confirm.ownRoleTitle.${pending.role}`)}</AlertDialogTitle>
      <AlertDialogDescription>
        {pending.role === "employee" ? t("team.confirm.ownRoleEmployee") : t("team.confirm.ownRoleDescription")}
      </AlertDialogDescription>
    </AlertDialogHeader>
  )
}

/** Each status's badge; its text is `team.status.<status>`. */
const statusVariants: Record<MemberStatus, "secondary" | "outline" | "ghost"> = {
  active: "secondary",
  pending: "outline",
  deactivated: "ghost",
}

interface MemberRowProps {
  member: Member
  isSelf: boolean
  actorRole: Role
  lastOwner: boolean
  busy: boolean
  onRoleChange: (role: Role) => void
  onResend: () => void
  onInvitationLink: () => void
  onDeactivate: () => void
  onReactivate: () => void
}

function MemberRow({
  member,
  isSelf,
  actorRole,
  lastOwner,
  busy,
  onRoleChange,
  onResend,
  onInvitationLink,
  onDeactivate,
  onReactivate,
}: MemberRowProps) {
  const format = useDateTimeFormat()
  const { t } = useTranslation("admin")
  const active = isActive(member)
  const allowed = canActOn(actorRole, member)
  const roles = assignableRoles(actorRole)
  const status = statusOf(member)

  const canChangeRole = allowed && active && roles.length > 1
  const canResend = allowed && active && member.invitationPending
  const canDeactivate = allowed && active && !lastOwner
  const canReactivate = allowed && !active

  return (
    <TableRow className={active ? undefined : "text-muted-foreground"}>
      <TableCell className="pl-4 font-medium">
        <span className="flex items-center gap-2">
          {fullName(member)}
          {isSelf && <Badge variant="outline">{t("team.you")}</Badge>}
        </span>
      </TableCell>
      <TableCell>{member.email}</TableCell>
      <TableCell>
        {canChangeRole ? (
          <RoleSelect role={member.role} roles={roles} lastOwner={lastOwner} disabled={busy} onChange={onRoleChange} />
        ) : (
          roleLabel(member.role)
        )}
      </TableCell>
      <TableCell>
        <Badge variant={statusVariants[status]}>{t(`team.status.${status}`)}</Badge>
      </TableCell>
      <TableCell>{format.date(member.joinedAt)}</TableCell>
      <TableCell className="pr-4 text-right">
        {(canResend || canDeactivate || canReactivate) && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon-sm" disabled={busy} aria-label={t("team.actionsFor", { name: fullName(member) })}>
                <MoreHorizontalIcon />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {canResend && (
                <DropdownMenuItem onSelect={onResend}>
                  <MailIcon />
                  {t("team.resendInvitation")}
                </DropdownMenuItem>
              )}
              {canResend && (
                <DropdownMenuItem onSelect={onInvitationLink}>
                  <LinkIcon />
                  {t("team.invitationLink")}
                </DropdownMenuItem>
              )}
              {canReactivate && (
                <DropdownMenuItem onSelect={onReactivate}>
                  <UserCheckIcon />
                  {t("team.reactivate")}
                </DropdownMenuItem>
              )}
              {canDeactivate && (
                <DropdownMenuItem variant="destructive" onSelect={onDeactivate}>
                  <UserXIcon />
                  {t("team.deactivate")}
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      </TableCell>
    </TableRow>
  )
}

interface RoleSelectProps {
  role: Role
  roles: Role[]
  lastOwner: boolean
  disabled: boolean
  onChange: (role: Role) => void
}

function RoleSelect({ role, roles, lastOwner, disabled, onChange }: RoleSelectProps) {
  const { t } = useTranslation("admin")
  const select = (
    <Select value={role} onValueChange={(value) => onChange(value as Role)} disabled={disabled || lastOwner}>
      <SelectTrigger size="sm" aria-label={t("team.column.role")} className="w-32">
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
  )

  if (!lastOwner) return select

  // A disabled trigger gets no pointer events, so the tooltip hangs on a wrapper.
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex" tabIndex={0}>
          {select}
        </span>
      </TooltipTrigger>
      <TooltipContent>{t("team.lastOwner")}</TooltipContent>
    </Tooltip>
  )
}
