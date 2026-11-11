import { ApiError, type components } from "@business-tool/api-client"

export type Member = components["schemas"]["Member"]
export type Role = Member["role"]

export const roles: { value: Role; label: string }[] = [
  { value: "owner", label: "Owner" },
  { value: "manager", label: "Manager" },
  { value: "employee", label: "Employee" },
]

export function roleLabel(role: Role): string {
  return roles.find((r) => r.value === role)?.label ?? role
}

/*
 * The rules below mirror the Organization aggregate so the page only offers what the
 * API would allow. The API stays the source of truth: its 403 and 409 still reach the user.
 */

/** Owners and managers may see and manage the team; employees get a 403 from GET /members. */
export function canManageTeam(actor: Role | undefined): boolean {
  return actor === "owner" || actor === "manager"
}

/** Roles the actor may give (MembershipRole::canAssign): owners any, managers employee only. */
export function assignableRoles(actor: Role | undefined): Role[] {
  if (actor === "owner") return ["owner", "manager", "employee"]
  if (actor === "manager") return ["employee"]
  return []
}

/** Owners act on anyone, themselves included; managers act on employees only. */
export function canActOn(actor: Role | undefined, target: Member): boolean {
  if (actor === "owner") return true
  return actor === "manager" && target.role === "employee"
}

export function isActive(member: Member): boolean {
  return member.deactivatedAt === null
}

/** The organization keeps at least one active owner: that one can't be demoted or deactivated. */
export function isLastActiveOwner(member: Member, members: Member[]): boolean {
  return (
    member.role === "owner" && isActive(member) && members.filter((m) => m.role === "owner" && isActive(m)).length === 1
  )
}

export type MemberStatus = "active" | "pending" | "deactivated"

export function statusOf(member: Member): MemberStatus {
  if (!isActive(member)) return "deactivated"
  return member.invitationPending ? "pending" : "active"
}

export function fullName(member: Pick<Member, "firstName" | "lastName">): string {
  return `${member.firstName} ${member.lastName}`.trim()
}

/** 422 violations from API Platform, keyed by property path. */
export function violationsOf(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError) || error.status !== 422) return {}
  const body = error.body as components["schemas"]["ConstraintViolation"] | null
  const result: Record<string, string> = {}
  for (const violation of body?.violations ?? []) {
    result[violation.propertyPath] ??= violation.message
  }
  return result
}

const UUID = "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"

/**
 * API error details name people and the organization by id (`User "0190…" may not …`).
 * Swap the ids for names the reader knows; `names` maps user ids to a name or "You".
 */
export function readableError(message: string, names: Record<string, string>): string {
  return message
    .replace(new RegExp(`User "(${UUID})"`, "g"), (_, id: string) => names[id] ?? "This person")
    .replace(new RegExp(`(O|o)rganization "${UUID}"`, "g"), (_, o: string) => (o === "O" ? "This" : "this") + " organization")
}
