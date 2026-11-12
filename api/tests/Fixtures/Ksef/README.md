# KSeF fixtures

Responses of the KSeF API 2.0 for `tests/Unit/Ksef/Infrastructure/Http/HttpKsefClientTest.php`
(adr/0023 §8). Tests and CI never call KSeF: the adapter tests feed these files to a `MockHttpClient`,
and the test env's HTTP clients throw on any request (`config/packages/http_client.yaml`).

Contract: API 2.9.0, https://api-test.ksef.mf.gov.pl/docs/v2/openapi.json (TEST) and
https://api.ksef.mf.gov.pl/docs/v2/openapi.json (PROD), checked on 2026-11-12.

| File | Endpoint | Origin |
|---|---|---|
| `public-key-certificates.json` | `GET /security/public-key-certificates` | recorded from TEST, 2026-11-12 |
| `auth-challenge.json` | `POST /auth/challenge` | recorded from TEST, 2026-11-12; `clientIp` replaced with 203.0.113.10 |
| `auth-ksef-token.json` | `POST /auth/ksef-token` (202) | OpenAPI `AuthenticationInitResponse`, placeholder token |
| `auth-status-*.json` | `GET /auth/{referenceNumber}` | OpenAPI `AuthenticationOperationStatusResponse`, codes and texts from its status table |
| `auth-token-redeem.json`, `auth-token-refresh.json` | `POST /auth/token/redeem`, `POST /auth/token/refresh` | OpenAPI, placeholder tokens |
| `invoices-query-metadata*.json` | `POST /invoices/query/metadata` | OpenAPI example, random NIPs |
| `invoice-fa3.xml` | `GET /invoices/ksef/{ksefNumber}` | a minimal FA(3) document, random NIPs; valid against the FA(3) XSD |
| `error-*.json` | any | OpenAPI problem details (`X-Error-Format: problem-details`) |

Files built from the OpenAPI should be replaced by recordings when someone has a TEST token.

## FA(3) samples (`Fa3/`)

Invoices for `tests/Unit/Ksef/Infrastructure/Fa3/Fa3InvoiceParserTest.php` (ADR 0023 §7–8). They follow
the shape of the Ministry's sample `KSeF.Client.Tests.Core/Templates/invoice-template-fa-3.xml` in
https://github.com/CIRFMF/ksef-client-csharp (commit d628369, 2026-11-09), with made-up parties, random
NIPs with a valid check digit, and amounts chosen for the case:

| File | Case |
|---|---|
| `vat-several-rates.xml` | `VAT` in PLN: 23 %, 8 %, 5 %, 0 % (`P_13_6_1`) and exempt (`P_13_7`) |
| `foreign-currency.xml` | `VAT` in EUR with the VAT in PLN (`P_14_xW`) |
| `correction.xml` | `KOR` with negative amounts and the corrected invoice's KSeF number |
| `advance.xml` | `ZAL` with an order (`Zamowienie`) |
| `unsupported-vat-groups.xml` | groups with no PL rate code: taxi flat rate (`P_13_4`) and reverse charge (`P_13_10`) |

Every FA(3) file here validates against `schemat_FA(3)_v1-0E.xsd` (https://github.com/CIRFMF/ksef-docs,
`faktury/schemy/FA/`, commit ce3670e, 2026-10-14). The schema imports its base types from crd.gov.pl;
point the import at the copies in `faktury/schemy/FA/bazowe/` to validate offline:

```bash
git clone --depth 1 https://github.com/CIRFMF/ksef-docs.git /tmp/ksef-docs && cd /tmp/ksef-docs/faktury/schemy/FA
sed 's#schemaLocation="http://crd.gov.pl/[^"]*/StrukturyDanych_v10-0E.xsd"#schemaLocation="bazowe/StrukturyDanych_v10-0E.xsd"#' 'schemat_FA(3)_v1-0E.xsd' > fa3-local.xsd
xmllint --noout --nonet --schema fa3-local.xsd <repo>/api/tests/Fixtures/Ksef/invoice-fa3.xml <repo>/api/tests/Fixtures/Ksef/Fa3/*.xml
```

Error cases are built in the test by changing one of these files.

## Rules

- No tokens, ever: replace every `token` value with a `fixture-…` placeholder before committing
  (KSeF tokens, authentication, access and refresh tokens alike). gitleaks runs on every PR.
- Only random NIPs. TEST's data is shared between integrators, so it must not hold real data either.
- Replace `clientIp` (your public address) with an address from 203.0.113.0/24.
- Re-record when `api-changelog.md` in https://github.com/CIRFMF/ksef-docs changes a contract we use.

## Recording

The public key certificates and the challenge need no authentication:

```bash
curl -s https://api-test.ksef.mf.gov.pl/v2/security/public-key-certificates | jq . > public-key-certificates.json
curl -s -X POST https://api-test.ksef.mf.gov.pl/v2/auth/challenge | jq '.clientIp = "203.0.113.10"' > auth-challenge.json
```

Everything else needs a KSeF token for a random NIP on TEST. TEST accepts a self-signed certificate,
so anyone can sign in for any NIP there once (XAdES, see `uwierzytelnianie.md` and
`auth/testowe-certyfikaty-i-podpisy-xades.md` in ksef-docs, or the official clients'
`KSeF.Client.Tests` E2E helpers) and generate a token with `InvoiceRead` (`POST /tokens`,
`tokeny-ksef.md`). Then run the client against TEST from a throwaway script in the `php` container,
with `KSEF_API_URL=https://api-test.ksef.mf.gov.pl/v2`, dump each response body and scrub it by the
rules above. Purchase invoices to query need a seller on TEST that sends an FA(3) invoice to the
random buyer NIP (`sesja-interaktywna.md`).
