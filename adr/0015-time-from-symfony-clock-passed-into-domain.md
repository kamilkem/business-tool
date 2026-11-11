# 0015. Time comes from Symfony Clock in the Application layer and is passed into the Domain

Status: Accepted

Date: 2026-11-11

## Context

Time tracking comes next: shifts with clock-in and clock-out times (#24), business dates in the
organization's time zone (#18), and later invoices and pay periods. Their rules are about time: how
long a shift lasted, which day it belongs to, whether a period is closed. A test can only check such
rules if it controls the current time, and it cannot stop the real clock.

Until now the code read "now" in two ways. `User`, `Organization` and `Membership` called
`new \DateTimeImmutable()` in their constructors. The newer code (one-time links, #9 and #13; member
deactivation, #11) already took the moment as a parameter, e.g. `PasswordToken::isValidAt($now)` and
`Organization::deactivateMember(..., $now)`. Its handlers read it from `Psr\Clock\ClockInterface`
(`symfony/clock`, already installed), and one API test froze time with
`ClockSensitiveTrait::mockTime()`, while others worked around the real clock by issuing tokens in the
past. Timestamps are stored in UTC (ADR [0010](0010-money-minor-units-and-utc-timestamps.md)), and
the Domain may depend on Symfony only for Uid and the security user interfaces
(ADR [0004](0004-layered-modules-and-cqrs-buses.md), enforced by Deptrac).

## Decision

1. **The Domain never reads the clock.** Entity constructors and methods that record or compare a
   moment take it as a `\DateTimeImmutable` parameter: `new User(..., $createdAt)`,
   `new Organization(..., $createdAt)`, `Organization::addMember(..., $now)`,
   `PasswordToken::isValidAt($now)`. Domain code never calls `new \DateTimeImmutable()` without an
   argument, nor `time()`, `date()` or `Clock::get()`, and it does not depend on `psr/clock`.
2. **The Application layer reads the clock.** Command and event handlers that need "now" inject
   `Psr\Clock\ClockInterface`, which is autowired to Symfony's `clock` service, and call `now()` once
   per use case, so every record written by one use case gets the same moment. Other entry points do
   the same: Ui dev fixtures, and console commands (they usually send a command whose handler reads
   the clock).
3. **Tests control the clock.** Unit tests construct `Symfony\Component\Clock\MockClock` and pass it
   to the handler, or pass a fixed `\DateTimeImmutable` to the entity. API and functional tests freeze
   or move the global clock with `ClockSensitiveTrait::mockTime()` (used in `ApiTestCase`). The
   `clock` service, the Foundry factories and the test mothers all read the global clock, and it is
   restored after each test.
4. **Time zones stay as ADR 0010 says.** The clock returns server time in UTC. Conversion to the
   organization's time zone happens where business dates are computed, not in the clock.

## Alternatives considered

- **Entities read the global clock** (`Clock::get()->now()` or `Symfony\Component\Clock\now()` in a
  constructor). Tests could still freeze it with `mockTime()`. Rejected: it hides a global dependency
  inside the model, and the Domain would depend on a framework component that ADR 0004 does not allow.
- **Pass a `ClockInterface` into entity methods** (`$token->isValidAt($clock)`). Rejected: the Domain
  would depend on `psr/clock`, and one use case could read several different moments. Passing the
  moment itself is simpler.
- **Our own clock port in `App\Shared`.** Rejected: `symfony/clock` already implements PSR-20 and
  ships `MockClock` and `ClockSensitiveTrait`. Our own interface would add an adapter and no benefit.
- **Keep `new \DateTimeImmutable()` and assert with a tolerance or with timestamps in the past.**
  Rejected: expiry boundaries, shift durations and period cut-offs cannot be tested exactly that way.

## Consequences

- Tests set exact moments and check boundaries (one second before and at expiry), as
  `PasswordTokenTest` already does. Clock-in and clock-out tests (#24) follow the same pattern.
- Constructors and methods that record time gain a required parameter, so every caller (handler,
  fixture, test mother, Foundry factory) passes the moment explicitly. This is more verbose, and it is
  accepted on purpose.
- `mockTime()` replaces a process-wide static. Framework services that read the `clock` service
  follow it too. Code that reads system time directly (`time()`, `microtime()`) does not, so a test
  that moves time must not rely on such code.
