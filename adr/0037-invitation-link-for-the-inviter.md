# 0037. Owners and managers can create an invitation link to pass on themselves, and the console prints the first owner's link

Status: Accepted

Date: 2026-11-24

## Context

ADR [0013](0013-member-access-by-one-time-email-link.md) gives a new member access through a one-time
link that only travels by e-mail: "nobody but the invited person … sees the password, and the manager
never sees the link" (point 1), and the secret "appears only in the e-mail" (point 3). It listed "an
e-mail link plus a 'copy invitation link' button" as an alternative, "not now; it can be added later
without changing the tokens".

Two things changed:

- **E-mail can't be relied on yet.** In production (ADR [0035](0035-staging-and-production-on-one-ovh-instance.md),
  ADR [0036](0036-transactional-email-through-ovh-mail-plan.md)) the host could not reach any SMTP port:
  the OVHcloud project's default security group blocks outbound 25, 465 and 587, and lifting it needs
  OVHcloud support. Until then no invitation reaches anyone, and an e-mail can also land in spam or go
  to a mistyped address.
- **Staff of a bar are often easier to reach by text message** than by e-mail.

The first owner of an organization is created on the host with `app:organization:create` (#219), whose
link went only by e-mail too, so a new customer could not get in at all while e-mail was down.

## Decision

1. **An owner or manager can create an invitation link for a member who has not set a password yet**,
   for the same members whose invitation they may resend: owners for anyone, managers for employees
   only.
   - **Endpoint:** `POST /api/members/{userId}/invitation-link` returns `{ link, validForDays }`. It
     issues a new invitation token exactly as a resend does (7 days, one use) but sends no e-mail.
   - **Earlier links stop working**, the e-mailed one included (ADR 0013 point 3: issuing a token
     invalidates the unused ones).
   - **Refusals are the same as a resend's:** 403 for a member the actor may not act on, 404 for a
     non-member, 409 for someone who already has a password or is deactivated.
2. **The admin offers it where an invitation is pending.**
   - **Where:** in the member's menu on the Team page, and after adding a person without an account,
     next to the "invitation sent" message.
   - **Created on request:** the link is never created automatically. Creating it replaces the
     e-mailed link, so the admin says so first.
   - **What the admin shows:** the link with a copy button and its validity, and a warning that whoever
     opens it sets the password, so it goes to that person only.
3. **`app:organization:create` prints the new owner's link** next to queuing the invitation e-mail,
   with the same token: whoever runs it on the host can pass the link on when the e-mail does not
   arrive. The command still never prints a link for an existing user, who has no token.
4. **The link carries the secret as before** (`…/set-password#token=…`, ADR 0013 point 6). The Ui
   generates the secret for these two paths, as it does for refresh tokens
   (ADR [0020](0020-rotating-refresh-tokens-in-httponly-cookie.md) point 7): it passes it in
   `SendInvitationCommand` (a `#[\SensitiveParameter]`), the handler stores only its hash, and the Ui
   builds the link from it. The secret is never logged, put in an event or stored in plain text.

This supersedes, in ADR 0013, the words "the manager never sees the link" (point 1) and "it appears
only in the e-mail" (point 3), for these two paths. Everything else in ADR 0013 stands: one-time
tokens, their lifetimes, the hash at rest, set-password not signing in, and links built from configured
URLs.

## Alternatives considered

- **Show the link automatically after adding a member.** It would replace the e-mailed link at once
  without the owner choosing to, so an e-mail that does arrive would carry a dead link.
- **Return the e-mailed link from "add member".** The invitation is sent by an event handler after the
  membership is saved (ADR 0013); handing its secret back to the caller would mean carrying a secret
  through a domain event.
- **An initial password set by the manager** (rejected in ADR 0013): the manager would know the
  password and could sign in as the employee. With a link, the person still chooses their own password,
  and the link stops working once used.
- **Wait for e-mail to work.** Onboarding and every new employee would depend on a support ticket.

## Consequences

- **The inviter can hand over access**, and with it the power to set the person's password. The admin
  says so, the link works once and for 7 days, and a new link or e-mail replaces it. Someone who opens
  it first could take over a pending account. That risk already existed for whoever could read the
  invitee's e-mail, and the member's role and organization still come from the membership.
- **The link is shown once.** Copying it again means creating a new one, which invalidates the last.
- **The response carries a secret.** It is never cached and never logged; the access log has the path
  only. The frontend keeps it in component state, not in the query cache or storage.
- When e-mail works again, nothing changes for invitations sent by e-mail; the link stays a fallback.
