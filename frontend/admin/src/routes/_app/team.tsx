import { useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute, useNavigate, useRouter } from "@tanstack/react-router"
import { MailIcon, MoreHorizontalIcon, UserCheckIcon, UserXIcon } from "lucide-react"
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

import { AddMemberDialog } from "@/components/add-member-dialog"
import { api } from "@/lib/api"
import {
  type Member,
  type MemberStatus,
  type Role,
  assignableRoles,
  canActOn,
  canManageTeam,
  fullName,
  isActive,
  isLastActiveOwner,
  readableError,
  roleLabel,
  statusOf,
} from "@/lib/members"
import { meQuery, membersQuery } from "@/lib/queries"

export const Route = createFileRoute("/_app/team")({
  validateSearch: (search: Record<string, unknown>): { deactivated?: boolean } => ({
    deactivated: search.deactivated === true || search.deactivated === "true" ? true : undefined,
  }),
  loaderDeps: ({ search }) => ({ deactivated: search.deactivated ?? false }),
  loader: ({ context, deps }) => {
    const id = context.membership?.organization.id
    if (id && canManageTeam(context.membership?.role)) {
      return context.queryClient.ensureQueryData(membersQuery(id, deps.deactivated))
    }
  },
  component: TeamPage,
})

function TeamPage() {
  const { membership } = Route.useRouteContext()

  if (!membership || !canManageTeam(membership.role)) {
    return (
      <div className="mx-auto max-w-2xl">
        <Card>
          <CardHeader>
            <CardTitle>Team</CardTitle>
            <CardDescription>Only owners and managers can see and manage the team.</CardDescription>
          </CardHeader>
        </Card>
      </div>
    )
  }

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

  /** After any change: refresh the list, and the signed-in user's own access when they changed themselves. */
  async function refresh(member: Member) {
    // Own access first: after stepping down to employee (or deactivating oneself) the page
    // stops showing the list, so it doesn't refetch one the API would now refuse.
    if (member.userId === me.id) {
      // "me" has no observer (the layout reads it in beforeLoad), so refetch it explicitly.
      await queryClient.invalidateQueries({ queryKey: meQuery.queryKey, refetchType: "all" })
      await router.invalidate()
    }
    await queryClient.invalidateQueries({ queryKey: ["members", organizationId] })
  }

  /** The API refused: show why, and reload the list, which was probably out of date. */
  function onError(title: string) {
    return async (error: Error) => {
      const names = Object.fromEntries(members.map((m) => [m.userId, m.userId === me.id ? "You" : fullName(m)]))
      toast.error(title, { description: readableError(error.message, names) })
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
      toast.success(`${fullName(updated)} is now ${roleLabel(updated.role).toLowerCase()}`)
      await refresh(member)
    },
    onError: onError("Could not change the role"),
  })

  const resendInvitation = useMutation({
    mutationFn: (member: Member) =>
      unwrap(api.POST("/api/members/{userId}/invitation", { params: { path: { userId: member.userId } } })),
    onSuccess: async (_, member) => {
      toast.success(`Invitation sent again to ${member.email}`)
      await refresh(member)
    },
    onError: onError("Could not resend the invitation"),
  })

  const deactivate = useMutation({
    mutationFn: (member: Member) =>
      unwrap(api.POST("/api/members/{userId}/deactivate", { params: { path: { userId: member.userId } } })),
    onSuccess: async (_, member) => {
      toast.success(`${fullName(member)} no longer has access`)
      await refresh(member)
    },
    onError: onError("Could not deactivate the member"),
  })

  const reactivate = useMutation({
    mutationFn: (member: Member) =>
      unwrap(api.POST("/api/members/{userId}/reactivate", { params: { path: { userId: member.userId } } })),
    onSuccess: async (_, member) => {
      toast.success(`${fullName(member)} has access again`)
      await refresh(member)
    },
    onError: onError("Could not reactivate the member"),
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
    <div className="mx-auto flex max-w-5xl flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Team</h1>
          <p className="text-sm text-muted-foreground">
            {actorRole === "owner"
              ? "People with access to this organization."
              : "People with access to this organization. Managers manage employees only."}
          </p>
        </div>
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-2">
            <Switch
              id="show-deactivated"
              checked={deactivated}
              onCheckedChange={(checked) => navigate({ search: { deactivated: checked || undefined } })}
            />
            <Label htmlFor="show-deactivated">Show deactivated</Label>
          </div>
          <AddMemberDialog organizationId={organizationId} actorId={me.id} actorRole={actorRole} />
        </div>
      </div>

      <Card className="py-0">
        <CardContent className="px-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-4">Name</TableHead>
                <TableHead>E-mail</TableHead>
                <TableHead>Role</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Joined</TableHead>
                <TableHead className="pr-4">
                  <span className="sr-only">Actions</span>
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
                  onDeactivate={() => setPending({ kind: "deactivate", member })}
                  onReactivate={() => reactivate.mutate(member)}
                />
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <AlertDialog open={pending !== null} onOpenChange={(open) => !open && setPending(null)}>
        <AlertDialogContent>
          {pending && <ConfirmText pending={pending} isSelf={pending.member.userId === me.id} />}
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction variant={pending?.kind === "deactivate" ? "destructive" : "default"} onClick={confirm}>
              {pending?.kind === "deactivate" ? "Deactivate" : "Change role"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

function ConfirmText({ pending, isSelf }: { pending: Pending; isSelf: boolean }) {
  const name = fullName(pending.member)
  if (pending.kind === "deactivate") {
    return (
      <AlertDialogHeader>
        <AlertDialogTitle>{isSelf ? "Deactivate yourself?" : `Deactivate ${name}?`}</AlertDialogTitle>
        <AlertDialogDescription>
          {isSelf
            ? "You will lose access to this organization right away. Another owner can reactivate you."
            : "They lose access to this organization right away, and a pending invitation stops working. Their history stays, and you can reactivate them later."}
        </AlertDialogDescription>
      </AlertDialogHeader>
    )
  }
  return (
    <AlertDialogHeader>
      <AlertDialogTitle>Change your own role to {roleLabel(pending.role).toLowerCase()}?</AlertDialogTitle>
      <AlertDialogDescription>
        {pending.role === "employee"
          ? "Employees can't manage the team or the organization settings. Only another owner can give you your role back."
          : "Only another owner can give you your role back."}
      </AlertDialogDescription>
    </AlertDialogHeader>
  )
}

const statusBadges: Record<MemberStatus, { label: string; variant: "secondary" | "outline" | "ghost" }> = {
  active: { label: "Active", variant: "secondary" },
  pending: { label: "Invitation pending", variant: "outline" },
  deactivated: { label: "Deactivated", variant: "ghost" },
}

const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: "medium" })

interface MemberRowProps {
  member: Member
  isSelf: boolean
  actorRole: Role
  lastOwner: boolean
  busy: boolean
  onRoleChange: (role: Role) => void
  onResend: () => void
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
  onDeactivate,
  onReactivate,
}: MemberRowProps) {
  const active = isActive(member)
  const allowed = canActOn(actorRole, member)
  const roles = assignableRoles(actorRole)
  const status = statusBadges[statusOf(member)]

  const canChangeRole = allowed && active && roles.length > 1
  const canResend = allowed && active && member.invitationPending
  const canDeactivate = allowed && active && !lastOwner
  const canReactivate = allowed && !active

  return (
    <TableRow className={active ? undefined : "text-muted-foreground"}>
      <TableCell className="pl-4 font-medium">
        <span className="flex items-center gap-2">
          {fullName(member)}
          {isSelf && <Badge variant="outline">You</Badge>}
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
        <Badge variant={status.variant}>{status.label}</Badge>
      </TableCell>
      <TableCell>{dateFormat.format(new Date(member.joinedAt))}</TableCell>
      <TableCell className="pr-4 text-right">
        {(canResend || canDeactivate || canReactivate) && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon-sm" disabled={busy} aria-label={`Actions for ${fullName(member)}`}>
                <MoreHorizontalIcon />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {canResend && (
                <DropdownMenuItem onSelect={onResend}>
                  <MailIcon />
                  Resend invitation
                </DropdownMenuItem>
              )}
              {canReactivate && (
                <DropdownMenuItem onSelect={onReactivate}>
                  <UserCheckIcon />
                  Reactivate
                </DropdownMenuItem>
              )}
              {canDeactivate && (
                <DropdownMenuItem variant="destructive" onSelect={onDeactivate}>
                  <UserXIcon />
                  Deactivate
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
  const select = (
    <Select value={role} onValueChange={(value) => onChange(value as Role)} disabled={disabled || lastOwner}>
      <SelectTrigger size="sm" aria-label="Role" className="w-32">
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
      <TooltipContent>The organization needs at least one active owner.</TooltipContent>
    </Tooltip>
  )
}
