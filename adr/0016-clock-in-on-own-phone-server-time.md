# 0016. Employees clock in on their own phones with their own account; the server's clock sets the time

Status: Accepted

Date: 2026-11-11

## Context

Time tracking is next (#24–#34), and the first bar goes live when its staff can clock in and out
(#39). Worked hours feed payroll (#59–#62), so a shift's times are money: whoever can set them sets
someone's pay. Before building the Shift aggregate (#24) and the clock-in API (#25), four questions
needed an answer (#23):

- **Device.** The time clock PWA exists (ADR [0008](0008-two-frontend-apps-in-one-pnpm-workspace.md))
  with the e-mail and password sign-in of ADR [0007](0007-stateless-jwt-authentication.md), and every
  member already has an e-mail address and a password of their own
  (ADR [0013](0013-member-access-by-one-time-email-link.md)). The alternative is a shared device at the
  venue, which needs a credential that is not a person's password.
- **Location.** Nothing stops someone from clocking in at home. The ways to check presence (browser
  geolocation, the venue's IP or Wi-Fi, a QR code at the venue) differ in cost, reliability on phones
  and in how much personal data about employees they collect.
- **Edits.** Someone has to fix a forgotten clock-out or a missing shift. If employees could change
  their own times, they would set their own pay.
- **Which clock.** The server's clock (ADR [0015](0015-time-from-symfony-clock-passed-into-domain.md))
  or a time sent by the phone, which offline clock-in (#34) would need.

Constraints: the first customer is a small bar where an owner or manager is present on every shift
and knows who is working. JWTs live 1 hour and there are no refresh tokens (ADR 0007).

## Decision

1. **Employees clock in and out on their own phones, signed in to the time clock with their own
   account.** Clock-in and clock-out apply to the signed-in user only; nobody clocks in for someone
   else. ADR 0007 is unchanged, including the 1-hour token: an employee whose token has expired signs
   in again before clocking out. Refresh tokens are a separate ticket (#82) with its own ADR that
   supersedes ADR 0007 point 4.
2. **No shared device for now.** A clock-in device at the venue (kiosk) is a separate ticket (#83).
   It will sign in as a different type of account (a device, not a person) and needs its own ADR
   before any code.
3. **No location check.** The API neither asks for nor stores the employee's location, and does not
   check the venue's IP, Wi-Fi or a QR code. Owners and managers review shifts (#31) and correct them
   (#27). If a venue needs a presence check later, it is a new ADR, and its parameters are
   organization settings (ADR [0002](0002-locale-specifics-behind-organization-settings.md)).
4. **The server's clock sets clock-in and clock-out times.** The endpoints accept no time from the
   client; the handler reads `ClockInterface` (ADR 0015). The only planned exception is offline
   clock-in (#34): events queued on the phone may carry the device's time, accepted only within
   limits (not in the future, not before the person's previous clock event, not older than a set
   maximum), stored next to the time the server received them and marked so managers can see them.
   #34 designs that exception; #25 does not include it.
5. **Owners and managers change shifts; employees ask for changes.** Owners and managers add, edit
   and delete any member's shifts directly (#27, #28), and the shift records who corrected it and
   when. Employees never change a shift's times themselves. They submit a shift change request (a
   missing shift, or new times for one of their own shifts) with a note explaining it, and an owner or
   manager approves it, which applies the change, or rejects it. Requests are their own aggregate in
   `App\TimeTracking` with at least the states pending, approved and rejected, and come after
   go-live (#84, #85, #86).

## Alternatives considered

- **A shared tablet at the venue with a PIN per employee, now.** Being at the tablet implies being
  at the venue, and it works for staff without a smartphone. But it adds a new credential type and
  security boundary (pairing and revoking devices, short PINs that need lockouts), the device has to
  be bought and looked after, and the first bar does not need it. Deferred to #83, not rejected.
- **Browser geolocation with a radius around the venue.** The employee's location is personal data:
  staff must be told about the monitoring in advance, and we would have to keep as little as
  possible. Phones ask for permission (on iOS often again and again in a PWA), accuracy indoors is
  tens of meters, a browser's location is easy to fake, and a refused permission blocks clocking in.
- **The venue's public IP or Wi-Fi.** No prompt and no new data, but many venues have a dynamic IP,
  phones are often on mobile data instead of the Wi-Fi, IPv6 and proxies complicate the check, and an
  outdated IP list locks out everyone.
- **A QR code at the venue.** A printed code can be photographed and scanned from home; a code that
  changes needs a screen at the venue, which is a device there anyway (then the kiosk is simpler).
- **A client-supplied time from day one.** It would allow offline clock-in early, but a phone's clock
  can be set back on purpose, so every shift would need checking.
- **Employees edit their own shifts.** Simple, but shift times are pay, so a person would set their
  own.
- **A longer JWT lifetime until refresh tokens exist.** Fewer sign-ins at clock-out, but a stolen
  token stays usable longer (ADR 0007). Signing in again is accepted instead.

## Consequences

- No new credential, no new kind of endpoint and no new personal data: #25 builds on the existing
  sign-in and the per-request membership check (ADR 0001). ADRs 0007 and 0013 stay as they are.
- Every employee needs a smartphone with a browser and an e-mail address (the latter already from
  ADR 0013). Until the kiosk exists (#83), a manager records the shifts of someone without one (#27).
- Anyone can clock in from anywhere, and a person who knows a colleague's password can clock in as
  them. This is accepted for a small venue where a manager sees who is working; their review is the
  control, so the shifts list (#31) must make an odd shift easy to spot.
- With 1-hour tokens, clocking out after a normal shift usually starts with signing in again. The
  time clock must bring the person straight back to the clock screen after sign-in (#29). Accepted
  until refresh tokens (#82).
- Times are the server's moments; changing the phone's clock does nothing. Without a connection
  there is no clock-in until #34 exists; a manager adds the shift instead.
- Every change to a recorded time can be traced to an owner or manager: directly (#27) or by approving
  an employee's request (#84). The request flow adds an aggregate, e-mails (ADR
  [0012](0012-transactional-email-smtp-async-queue.md)) and screens in both apps after go-live.
