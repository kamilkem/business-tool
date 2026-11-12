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
| `invoice-fa3.xml` | `GET /invoices/ksef/{ksefNumber}` | a minimal FA(3) document, random NIPs (#51 brings full samples) |
| `error-*.json` | any | OpenAPI problem details (`X-Error-Format: problem-details`) |

Files built from the OpenAPI should be replaced by recordings when someone has a TEST token.

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
