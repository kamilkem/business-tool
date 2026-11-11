# 0010. Money is stored as integer minor units with a currency; timestamps are stored in UTC

Status: Accepted

Date: 2026-11-10

## Context

The planned modules are about money and time: invoice amounts, daily revenue, hours worked, pay.
Floating-point amounts accumulate rounding errors, and local timestamps break around daylight-saving
changes and across organizations in different time zones
(ADR [0002](0002-locale-specifics-behind-organization-settings.md)).

## Decision

1. **Money** is an integer number of minor units (grosze for PLN) together with an ISO 4217 currency
   code. Never `float`, never a decimal string used for arithmetic.
2. **Timestamps** are `DateTimeImmutable` and stored in UTC. Conversion to the organization's time
   zone happens when computing business dates (a shift's day, a pay period) and when displaying.

## Consequences

- Sums and splits are exact; rounding happens only where a rule says so (e.g. VAT), and that rule
  states its rounding mode.
- "Which day does this belong to" is always computed with an explicit time zone, never with the
  server's default.
