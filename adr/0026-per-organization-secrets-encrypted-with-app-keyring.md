# 0026. Per-organization secrets are encrypted in the database with libsodium under an application keyring, in their own module, and never leave it through the API

Status: Proposed

Date: 2026-11-12

## Context

The KSeF import (ADR [0023](0023-ksef-purchase-invoice-import.md) §2, ADR
[0024](0024-expenses-independent-of-ksef.md) §3) authenticates each organization with a **KSeF token**:
a string the owner generates in KSeF with the `InvoiceRead` permission and pastes into the admin
(#49). Anyone holding it can read all the organization's purchase invoices in KSeF. It belongs to one
organization, must work in scheduled runs with no user present (#52), and must not sit in the database
in plain text, where every backup, dump and SQL session would expose it. A KSeF certificate with its
private key and passphrase may follow (ADR 0023, Alternatives), so the storage cannot assume one string
per organization. The spike (#46) had to decide the storage before #49.

What the issue already fixes: the API never returns the value, only whether one is set and when; only
owners set it (ADR 0023 §2); a secret is readable only after `switchTo()` of the organization that owns
it (ADR [0001](0001-multi-tenancy-shared-database-organization-filter.md)); its only readers in v1 are
"test connection" (#49) and the sync (#52); and each secret records its kind (`ksef_token` now), so that
other kinds fit later without migrating data.

Constraints:

- Production hosting (#35) is not decided. The leading option is a VPS with Docker Compose and
  self-hosted PostgreSQL, which has no managed key service. Whatever we choose must work there and not
  rule out a managed one later.
- The PHP image (`dunglas/frankenphp:1-php8.4`), local PHP and CI's `setup-php` all have the `sodium`
  extension (libsodium 1.0.18 in the image), so authenticated encryption needs no new dependency.
- The buses are synchronous; only Mailer's messages go to the async queue (ADR
  [0012](0012-transactional-email-smtp-async-queue.md)), and the jobs runtime of #22 is being decided
  in parallel. Modules use each other's Application layer only through the buses (ADR
  [0004](0004-layered-modules-and-cqrs-buses.md)). Time comes from the clock in handlers (ADR
  [0015](0015-time-from-symfony-clock-passed-into-domain.md)), and ids are UUID v7 from the caller (ADR
  [0009](0009-uuid-v7-identifiers-assigned-by-caller.md)).
- Production logs are JSON on stderr (`monolog.yaml`). In dev and test, `.env.dev` and `.env.test`
  already carry committed dev-only values (`JWT_PASSPHRASE`), while `.env` is also loaded in production.

## Decision

1. **A module `App\Secret` stores every per-organization secret.** Its aggregate `OrganizationSecret`
   is tenant-aware (`TenantAwareInterface`, so the `TenantFilter` of ADR 0001 applies) and holds:
   an id (UUID v7 from the caller), its `kind` (enum `SecretKind`, one case `ksef_token` for now, never
   changed after creation), the encrypted value (`keyId`, `nonce`, `ciphertext`, base64 in `TEXT`
   columns), and who set it and when (`setBy` user id, `setAt`). Setting it again replaces the
   encrypted value and `setBy`/`setAt` in place, so the id stays stable. A kind with several parts
   (a certificate, its private key and passphrase) encrypts one JSON document whose shape the kind
   defines; a new kind adds a case and needs no migration. The consumer keeps only the secret's id
   (`KsefConnection` holds `secretId`).
2. **Authenticated encryption with libsodium, bound to its row.** Values are encrypted with
   `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt()`, with a fresh 24-byte random nonce on every
   write and the associated data `v1|<organizationId>|<secretId>|<kind>`. A ciphertext copied into
   another row or another organization, or altered, fails authentication instead of decrypting. The
   port `App\Secret\Domain\SecretCipherInterface` (`encrypt(plaintext, associatedData): EncryptedValue`,
   `decrypt(EncryptedValue, associatedData): string`) is implemented by
   `App\Secret\Infrastructure\Sodium\SodiumSecretCipher`. Command handlers encrypt and pass the
   `EncryptedValue` into the aggregate; the Domain never sees the key, and plaintext only passes through
   it as a parameter.
3. **The key comes from an application keyring in the environment.** `SECRETS_ENCRYPTION_KEYS` holds
   comma-separated `<keyId>:<base64 32-byte key>` entries. The first one encrypts; every entry decrypts,
   chosen by the row's `keyId`. A console command (`app:secret:generate-key`) prints a new entry made
   with `sodium_crypto_aead_xchacha20poly1305_ietf_keygen()`. A malformed keyring or a key of the wrong
   length fails at the first use with an exception, never with a silent fallback.
   - **Dev and test:** fixed dev-only keyrings committed in `.env.dev` and `.env.test` (with a comment
     saying so, like `JWT_PASSPHRASE`), and *not* in `.env`, so a production deployment without the
     variable fails instead of using a known key. CI reads `.env.test`.
   - **Production and staging:** each environment has its own keyring, injected as an environment
     variable by the deployment. How it gets there (a host env file, Symfony's secrets vault, the
     provider's secret store) is part of the hosting decision (#35). It is never in the image or the
     repository, and it is backed up apart from database backups, so one leaked backup exposes no
     secret.
4. **Rotation by re-encryption.** To rotate: generate a key, put it first in the keyring and deploy;
   run `app:secret:re-encrypt` (`Ui\Console\Secret`), which, per the background-job pattern, goes
   through the organizations, calls `switchTo()` for each, asks the query bus for its secrets whose
   `keyId` is not the current one, and sends one `ReEncryptOrganizationSecretCommand` per secret
   (decrypt with the old key, encrypt with the new one, same associated data). The command is
   idempotent and can be run again after a failure. When it reports nothing left, the old key is removed
   from the keyring. A suspected key leak means the same steps at once and, because the value itself
   may be known, asking owners to generate new KSeF tokens. No scheduled rotation in v1.
5. **Only the owning module's buses reach a secret, and its plaintext never reaches the Ui.**
   - Commands: `SetOrganizationSecretCommand` (secret id, organization id, kind, plaintext, actor id)
     and `RemoveOrganizationSecretCommand`. Only an active owner may set or remove a secret: the
     handler resolves the actor through the query bus, as other modules do, and the aggregate throws a
     domain exception for anyone else (403), or reports a non-member as not found (404).
   - Queries: `GetOrganizationSecretQuery` returns metadata only (`id`, `kind`, `setAt`, `setBy`);
     `RevealOrganizationSecretQuery` (secret id, expected kind) returns the plaintext in a
     `RevealedSecretView` and is for Application code of a consumer (#49's "test connection",
     #52's sync) only. A Deptrac rule forbids `Ui` from depending on `RevealOrganizationSecretQuery`
     and `RevealedSecretView`, so no provider or processor can return a value.
   - `App\Secret` has no API resource. A consumer's resource shows whether a secret is set and when
     (e.g. `KsefConnection`: `tokenSet`, `tokenSetAt`), and its input for setting one is write-only.
     A consumer's own permission rules (KSeF: owners only, country `PL`) are checked in its own
     command, which the Ui processor sends together with the secret command.
6. **Plaintext appears nowhere it could be kept.** Every constructor parameter and property that
   carries a plaintext value (inputs, commands, `RevealedSecretView`) is marked `#[\SensitiveParameter]`
   and has no `__toString()`. Exception messages, domain events (`OrganizationSecretSet`,
   `OrganizationSecretRemoved`) and log entries carry the organization id, secret id, kind, key id and
   actor, never the value or the ciphertext. Commands with a plaintext are never routed to an async
   transport (ADR 0012), and a job message of #22 carries the secret's id, never its value. The module
   logs setting, removing and re-encrypting a secret at `info`, and an unreadable secret at `error`.
   A consumer's HTTP client must not log request bodies (Symfony HttpClient logs method and URL only).
7. **An unreadable secret is a missing secret.** If decryption fails (its `keyId` is no longer in the
   keyring, the keyring was lost, or the row was altered), `RevealOrganizationSecretQuery` throws
   `SecretUnreadableException`. The consumer treats it as rejected credentials (`KsefConnection` →
   `credentials_rejected`, ADR 0023 §5) and the owner saves the token again. There is no key escrow: a
   lost keyring costs every owner one re-entry, not data.
8. **Tests.** The sodium adapter is unit-tested for real (a fixed test key: round trip, a wrong key, a
   changed associated data, a key id missing from the keyring, a malformed keyring). Handlers use an
   in-memory repository and the real cipher with a test keyring. Functional tests prove the
   `TenantFilter` applies to `organization_secret`, and the API tests of every consumer prove that the
   value never appears in a response and that another organization's secret is invisible. Nothing
   leaves the process.

## Alternatives considered

- **Symfony's secrets vault (`secrets:set`) for the values.** It encrypts per-deployment configuration
  at build time; it cannot hold values that owners write at runtime, one per organization. It remains
  a candidate for holding the production keyring itself, which #35 decides.
- **A managed key service (cloud KMS, HashiCorp Vault) with envelope encryption.** Stronger key
  custody and audit, but the leading hosting option has none, every read of a secret would depend on a
  network service, and #35 is open. The port and the `keyId` per row let a later adapter wrap data keys
  with a KMS without changing the consumers.
- **PostgreSQL `pgcrypto`.** The key would travel in SQL statements, where statement logs and
  `pg_stat_statements` can keep it, and the database would hold both the key and the data.
- **Plain text, relying on disk encryption.** Protects against a stolen disk only; any SQL access, dump
  or backup would expose every token.
- **A library (`paragonie/halite`, a Doctrine encryption bundle).** A dependency for a few lines of
  libsodium; transparent decryption in a Doctrine listener would also decrypt a secret every time its
  entity loads, instead of only where it is used.
- **`sodium_crypto_secretbox` with a single key.** It has no associated data, so a ciphertext could be
  moved between rows or organizations unnoticed, and changing the key would mean every owner re-enters
  their tokens.
- **The encrypted value on the owning aggregate** (an `EncryptedSecret` value object in
  `KsefConnection`, a cipher port in `App\Shared`). Less wiring for one consumer, but every future
  table holding a secret would need its own re-encryption, metadata and audit code, and rotation would
  have to know all of them.
- **Key escrow** (a required offline copy of every key). A lost keyring only costs owners one token
  re-entry, which does not justify another place where the key lives.

## Consequences

- Every environment needs `SECRETS_ENCRYPTION_KEYS`; production fails loudly without it. Keeping,
  backing up (apart from database backups) and rotating the keyring becomes part of the deployment
  runbook of #35, and #35's question about secrets management covers it.
- Whoever holds the keyring and a database backup can read every organization's tokens; keeping the
  two apart is the protection. A compromise of the running application still exposes the secrets it
  can decrypt: encryption at rest does not defend against that.
- A new module to register (`config/services.yaml`, Doctrine mapping, exception mapping, a migration
  for `organization_secret`) and a Deptrac rule, all with #49, which builds `App\Secret` with its first
  consumer. `CLAUDE.md` gets a Patterns row for storing a per-organization secret in the same change.
- `App\Ksef` stores and reads its token only through the buses: setting a token is two commands from
  the Ui processor (the secret, then the connection's state), and each sync run reveals the token once,
  keeps it in memory for the run only (ADR 0023 §2) and drops it.
- Losing a key, or a row that no longer decrypts, turns into `credentials_rejected` for that
  organization, which the admin already shows; there is no recovery beyond a new token.
- A KSeF certificate later is a new `SecretKind` with a JSON payload, without migrating data.
