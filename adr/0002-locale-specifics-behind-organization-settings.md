# 0002. Country, currency, time zone and tax ID are organization settings, not constants

Status: Accepted

Date: 2026-11-10

## Context

The first customer is a bar in Poland: Polish tax ID (NIP), PLN, `Europe/Warsaw`, invoices from
KSeF. The product is meant for small hospitality businesses in general, and the cheapest moment to
keep Poland-specific assumptions out of the core is before the first business module is written.

## Decision

1. Nothing in the code is bar- or Poland-specific unless it sits behind an organization setting.
2. `Organization` carries `countryCode`, `currency`, `timezone` and an optional `taxId`. They default
   to `PL`, `PLN` and `Europe/Warsaw`, the values of the first customer, and are changed through
   `Organization::changeSettings()`.
3. Country-specific integrations and rules (KSeF import, Polish payroll rules, NIP validation) are
   selected by the organization's country, not assumed globally.
4. Business dates ("today's revenue", "this pay period") are computed in the organization's time
   zone; storage stays in UTC (ADR [0010](0010-money-minor-units-and-utc-timestamps.md)).

## Consequences

- A second country is a matter of adding its integrations and rules, not of hunting down hard-coded
  `PLN` or `Europe/Warsaw`.
- Code that needs a currency, time zone or country reads it from the current organization, which is
  one more dependency for handlers that would otherwise be simpler.
- The defaults favor the first customer; a non-Polish organization has to set its values on creation.
