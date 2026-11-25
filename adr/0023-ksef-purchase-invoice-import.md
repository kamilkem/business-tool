# 0023. Purchase invoices are imported from KSeF API 2.0 with a KSeF token: metadata by permanent-storage date, one XML at a time, raw XML kept next to the parsed fields

Status: Accepted (module placement in points 1 and 3 and the XML table of point 6 superseded by [0024](0024-expenses-independent-of-ksef.md); the first run's start in point 4 superseded by [0038](0038-ksef-import-start-date-chosen-by-owner.md); the "Package export" alternative and the consequence on backlogs superseded by [0039](0039-ksef-package-export-for-backlogs.md))

Date: 2026-11-12

## Context

Expenses is the next milestone (#47–#58): owners and managers see what the venue bought and
categorise it. In Poland, invoices between businesses go through KSeF, the Ministry of Finance's
national e-invoice system. API 2.0 of KSeF is mandatory from 1 February 2026, and the structured
invoice format in it is FA(3). A purchase invoice that a supplier issues to the bar is in KSeF with the
bar's tax ID (NIP) as the buyer, so the import can replace typing invoices in by hand. The spike (#45)
had to settle how we talk to KSeF before the module scaffold (#47), the API client (#50) and the FA(3)
parser (#51).

What the official sources say (see Sources; API version 2.9.0, in production since 15 October 2026):

- **Authentication** gives a short-lived JWT `accessToken` (minutes) and a `refreshToken` (up to
  7 days) for one *context*, the company's NIP. The flow is asynchronous: `POST /auth/challenge` →
  `POST /auth/ksef-token` (a KSeF token) or `POST /auth/xades-signature` (an XML signed with XAdES:
  a qualified certificate, a qualified seal or a *KSeF certificate*) → poll `GET /auth/{referenceNumber}`
  → `POST /auth/token/redeem`. With a KSeF token, the string `token|timestampMs` is encrypted with
  KSeF's public key (`GET /security/public-key-certificates`, usage `KsefTokenEncryption`) using
  RSA-OAEP with SHA-256. A KSeF token has a fixed set of permissions (`InvoiceRead` is enough to
  read purchase invoices). It can only be generated after a one-time sign-in with a signature, and it
  is a secret.
- **KSeF tokens have an uncertain future.** Under the regulation in force, tokens work until
  31 December 2026, and from 2027 only certificates authenticate software. A draft amendment of the
  regulation (published for consultation on 27 October 2026, not signed as of 12 November 2026) keeps
  them: tokens created before 2027 would work until 30 November 2027, and new ones for up to 365 days.
  A KSeF certificate (type `Authentication`) is valid for up to two years, and MF recommends it for
  production because it skips the OCSP/CRL check of qualified certificates.
- **Reading invoices.** `POST /invoices/query/metadata` returns invoice metadata for one subject type
  (`Subject2` is the buyer) and a date range of at most 100 days, in pages of at most 250, with
  `hasMore` and `isTruncated` (10,000 records per filter set). For an incremental sync MF requires the
  `PermanentStorage` date type, ascending order and `restrictToPermanentStorageHwmDate`; the response's
  `permanentStorageHwmDate` is a high-water mark below which the results will not change. The invoice
  itself (FA(3) XML) comes from `GET /invoices/ksef/{ksefNumber}`. `POST /invoices/exports` builds an
  encrypted ZIP of many invoices asynchronously, recommended for high volumes. Metadata amounts are
  JSON doubles and carry no breakdown by VAT rate.
- **Rate limits** apply per pair (context, client IP address), in sliding windows. Metadata query:
  8/s, 16/min, 20/h. Single invoice download: 8/s, 16/min, 64/h. Exceeding a limit returns 429 with
  `Retry-After`, and repeated breaches lengthen the block and are reviewed by KSeF's security team.
  Limits are higher from 20:00 to 06:00. For low volumes MF recommends syncing on demand plus once a
  night, never more often than every 15 minutes, and serving users from a local copy: the API is not
  meant for showing an invoice when a user opens it.
- **Environments.** TEST (`api-test.ksef.mf.gov.pl`) accepts self-signed certificates, so any
  integrator can act for any NIP; its data is shared between integrators, it must hold no real data,
  and its limits are ten times higher. DEMO matches production configuration and limits. PROD is
  `api.ksef.mf.gov.pl`. The test environments may be under maintenance from 16:00 to 18:00.
- **FA(3)**: amounts are `xsd:decimal` with two fraction digits (`TKwotowy`), totals by VAT rate group
  (`P_13_x` net, `P_14_x` VAT, `P_14_xW` VAT in PLN for an invoice in another currency), `P_15` gross,
  `KodWaluty`, `RodzajFaktury` (`VAT`, `KOR`, `ZAL`, `ROZ`, `UPR`, `KOR_ZAL`, `KOR_ROZ`), and
  `DaneFaKorygowanej` for corrections. A correction may carry negative amounts.

Constraints from earlier decisions: the integration exists only for organizations whose country is
`PL` (ADR [0002](0002-locale-specifics-behind-organization-settings.md) §3); money is integer minor
units with a currency (ADR [0010](0010-money-minor-units-and-utc-timestamps.md)); a business date is a
`BusinessDate` (ADR [0017](0017-business-dates-and-periods-as-value-objects.md)); the admin is for
owners and managers (ADR [0021](0021-admin-for-owners-and-managers.md)); `symfony/http-client` is a
dev dependency (ADR [0012](0012-transactional-email-smtp-async-queue.md)); CI must not call external
services. PHP 8.4's `openssl_public_encrypt()` does RSA-OAEP only with SHA-1. Per-organization secret
storage (#46) and the background jobs runtime (#22) are separate open spikes.

## Decision

1. **KSeF API 2.0, through our own thin client.** A port in the Expenses domain
   (`App\Expenses\Domain\Ksef\KsefClientInterface`, final name in #50) and an adapter in
   `App\Expenses\Infrastructure\Ksef` on a scoped `symfony/http-client` (moved to production
   dependencies) and `phpseclib/phpseclib` 3 for RSA-OAEP with SHA-256. The adapter covers only what
   the import needs: the public key certificates, the KSeF-token authentication flow, refresh, session
   revocation, the metadata query and the single invoice download. The KSeF environment is one per
   deployment, set by `KSEF_API_URL`: TEST in development, TEST or DEMO in staging, PROD in production.
2. **Authentication with a KSeF token in v1.** The owner generates a token in KSeF for the
   organization's NIP with the `InvoiceRead` permission only, and pastes it into the admin (#49). The
   token is stored through the per-organization secret storage of #46: write-only, set by owners only.
   Each sync run authenticates anew (challenge, encrypted token, poll, redeem), refreshes the access
   token if it expires during the run, and ends with `DELETE /auth/sessions/current`. Access and
   refresh tokens are kept in memory for the run only, never stored. The public key for token
   encryption is cached until its `validTo`. The stored credential records its method (`ksef_token`),
   so that KSeF-certificate authentication can be added later without migrating data.
3. **Enabled per organization, Poland only.** A `KsefConnection` aggregate (tenant-aware, at most one
   per organization) in `App\Expenses` holds the reference to the stored token, its status
   (`active`, `credentials_rejected`, `disabled`), the sync cursor and the sync status (last run, last
   success, last error code and message, invoices imported in the last run). Creating or enabling it
   requires the organization's country `PL` and a tax ID that is a valid NIP; otherwise it is rejected
   (422). The admin shows the KSeF section only for `PL`. An organization whose country is no longer
   `PL` is skipped by the sync. Everything else in Expenses (the purchase invoice, categories, lists)
   is country-neutral; KSeF and FA(3) code lives in `Infrastructure\Ksef` and `KsefConnection`.
4. **Incremental sync by permanent-storage date.** A run queries metadata as the buyer (`Subject2`),
   with `dateType = PermanentStorage`, `sortOrder = Asc`, `restrictToPermanentStorageHwmDate = true`,
   `pageSize = 250`, a range of at most 100 days from the cursor, paging by `pageOffset` while
   `hasMore` and narrowing `from` to the last record's date when `isTruncated`. For each KSeF number not
   already stored it downloads the XML with `GET /invoices/ksef/{ksefNumber}`, checks it against the
   metadata's `invoiceHash` (SHA-256), parses it and saves the invoice. The cursor is a UTC instant:
   after a complete window it becomes the response's `permanentStorageHwmDate`; when a run stops early
   it stays at the `permanentStorageDate` of the last invoice saved in order. Windows may overlap;
   the KSeF number, unique per organization, makes the import idempotent. The first run starts at the
   first day of the previous month, in the organization's time zone, before the connection was
   created. Only `Subject2` is read: invoices where the organization is a third party or an authorized
   subject are out of scope.
5. **Schedule and limits.** The import runs for each active connection once an hour from 20:00 to
   06:00 Polish time, when KSeF's limits are higher (on the jobs runtime of #22), plus on demand: an
   owner or manager can start it from the admin, at most once every 15 minutes per organization.
   Organizations are processed one after another from one worker, so the limits of each NIP are
   never shared between parallel runs. A 429 ends the run for that organization: the cursor stays, the
   error and `Retry-After` go into the sync status, and the next run continues. Network errors and 5xx
   answers are retried up to 3 times with backoff within the run. A rejected token (authentication
   refused or revoked) sets the status to `credentials_rejected` and stops scheduled runs until the
   owner saves a new token.
6. **The raw XML and the parsed fields are both kept, in PostgreSQL.** A purchase invoice imported from
   KSeF stores the downloaded XML unchanged in its own tenant-aware table (one row per invoice, with the
   form code, the SHA-256 hash and the download time), never loaded by list queries and never edited.
   The parsed fields live on the `PurchaseInvoice` aggregate and can be re-derived from the XML when the
   parser improves, without calling KSeF again. Previews and XML downloads in the app read the local
   copy.
7. **FA(3) only, amounts from the XML.** The parser (#51) reads FA(3) and takes every amount from the
   XML's decimal strings, converted to minor units without floats; metadata amounts are not used. It
   extracts the seller's NIP and name, the invoice number (`P_2`), the issue date (`P_1`, a
   `BusinessDate`), the currency, net, VAT and gross totals, lines per VAT rate (net, VAT, and VAT in
   PLN for another currency), the invoice kind, and for a correction the corrected invoice's KSeF
   number when given. A correction is its own purchase invoice with the amounts as printed, negative
   ones included. Invoices of another form code (FA(2), PEF, FA_RR) are recognised from the metadata's
   `formCode`, not downloaded, and counted as skipped in the sync status.
8. **No KSeF in CI.** The adapter is tested with `MockHttpClient` and responses recorded from TEST
   (JSON and FA(3) XML in `api/tests/Fixtures/Ksef/`, with random NIPs, no tokens, and a README on how
   to record them). Handlers and API tests use an in-memory `KsefClientInterface`. The test
   environment's HTTP client for KSeF is a mock, so a test that forgets a fixture fails instead of
   reaching the network. The parser is tested with anonymised FA(3) samples: a VAT invoice with several
   rates, another currency, a correction with negative amounts and an advance invoice. Developers try
   the real flow against TEST with a random NIP and a token generated there.

## Alternatives considered

- **KSeF certificate (XAdES) in v1.** It is the only method certain to work after 2026 and avoids the
  OCSP wait, but it needs an XAdES-BES signature implemented in PHP and the upload and storage of a
  private key with its passphrase. The token is far less code, and the credential model leaves room
  for the certificate as a follow-up.
- **Both methods in v1.** Twice the authentication code and admin UI before the first invoice is
  imported.
- **A community PHP library for KSeF.** Less code, but an unofficial dependency that has to keep up
  with the Ministry's frequent API releases, for a handful of endpoints.
- **Package export (`POST /invoices/exports`).** Fewer requests, but asynchronous polling, AES-256
  decryption, encrypting a symmetric key and unzipping parts. A venue receives tens to a few hundred
  purchase invoices a month, which fits the synchronous limits. Export is the step up if an
  organization's volume outgrows them.
- **Querying by issue or invoicing date.** MF states that only the permanent-storage date with the
  high-water mark guarantees that no invoice is missed between runs.
- **Taking the amounts from the metadata.** They are doubles (ADR 0010 forbids floats for money) and
  have no breakdown by VAT rate.
- **Parsed fields only.** A smaller database, but a parser fix would mean downloading the invoices
  again within the 64-an-hour limit, and a preview would have to call KSeF, which MF advises against.
- **Raw XML in object storage.** A new infrastructure dependency before hosting (#35) is decided. A
  later move only needs the XML table replaced by a reference.
- **The KSeF environment per organization.** Not needed: an organization does not test against
  TEST in production, and TEST must not hold real data.

## Consequences

- **Deadline risk.** If the draft amendment is not signed, KSeF tokens stop working on
  1 January 2027 and the import fails with `credentials_rejected` for every organization. KSeF-certificate
  authentication then becomes urgent. It is a follow-up issue, to be prioritised as soon as the
  regulation's fate is known.
- New production dependencies: `symfony/http-client` (ADR 0012 kept it in dev) and
  `phpseclib/phpseclib`. The application makes outgoing HTTPS calls, and KSeF counts limits per
  client IP: production should call KSeF from one stable address.
- An organization needs a valid NIP in its settings and an owner who can generate a KSeF token
  before anything is imported.
- A backlog is slow: at 64 invoice downloads an hour per NIP, a month of a few hundred invoices takes
  a few hours of night runs. The sync status shows progress; nothing else waits for it.
- `PurchaseInvoice` amounts can be negative (corrections), and an invoice may be in a currency other
  than the organization's, with VAT also in PLN. Expense summaries total per currency, as income does
  (ADR [0022](0022-income-daily-report-per-register.md)).
- The XML table grows with every invoice (typically a few to tens of kilobytes each); it is excluded
  from list queries and kept as long as the invoice.
- The Ministry releases API changes every few weeks (`api-changelog.md`). Someone has to follow it,
  and fixtures are re-recorded when a contract changes.
- `CLAUDE.md` gets a Patterns row for calling an external API (port in Domain, adapter in
  Infrastructure on a scoped HTTP client, recorded responses in tests, never the network in CI).

## Sources

- KSeF API 2.0 integrator guide, Ministry of Finance: https://github.com/CIRFMF/ksef-docs
  (`uwierzytelnianie.md`, `tokeny-ksef.md`, `certyfikaty-KSeF.md`, `auth/sesje.md`,
  `pobieranie-faktur/pobieranie-faktur.md`, `pobieranie-faktur/przyrostowe-pobieranie-faktur.md`,
  `pobieranie-faktur/hwm.md`, `limity/limity-api.md`, `srodowiska.md`, `api-changelog.md`,
  `faktury/schemy/FA/schemat_FA(3)_v1-0E.xsd`), read on 2026-11-12.
- OpenAPI: https://api-test.ksef.mf.gov.pl/docs/v2 (TEST), https://api.ksef.mf.gov.pl/docs/v2 (PROD).
- Official clients used as reference: https://github.com/CIRFMF/ksef-client-csharp,
  https://github.com/CIRFMF/ksef-client-java.
- Token validity under the draft amendment of the KSeF regulation (secondary sources, to recheck
  against Dziennik Ustaw): https://www.podatki.egospodarka.pl/198613,Tokeny-KSeF-nie-znikna-w-2027-roku-Ministerstwo-Finansow-rezygnuje-z-ich-wygaszenia,1,68,1.html,
  https://bezprawnik.pl/tokeny-ksef-2027/,
  https://edgp.gazetaprawna.pl/podatki/vat/artykuly/11337627,ksef-tokeny-zostaja-na-dluzej-beda-zmiany-w-oznaczaniu-platnosci.html.
