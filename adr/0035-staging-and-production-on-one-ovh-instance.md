# 0035. Staging and production start on one OVHcloud Public Cloud instance in Warsaw: two Compose projects behind one Caddy, with one shared PostgreSQL

Status: Accepted (point 12 superseded by [0036](0036-transactional-email-through-ovh-mail-plan.md))

Date: 2026-11-23

## Context

Every feature needed to onboard the first bar (#39) is merged, but nothing runs outside a laptop.
The hosting spike (#35) has to say where the API and PostgreSQL run, how the two SPAs are served,
where secrets live, how the workers run, and how staging relates to production, so that the deploy
pipeline (#36) and the backups (#37) can be built from it. Several earlier ADRs left a part of their
decision to it:

- **Same-origin `/api` per app** (ADR [0020](0020-rotating-refresh-tokens-in-httponly-cookie.md)):
  the refresh token is a `SameSite=Strict` cookie with `Path=/api/auth`, so each SPA must reach the
  API at `/api` on its own origin. An API on another site would not get the cookie.
- **Two long-running workers from the API image** (ADR [0012](0012-transactional-email-smtp-async-queue.md)
  point 5, ADR [0025](0025-background-jobs-scheduler-and-job-bus.md) point 8): `worker` (e-mail and
  the scheduler's ticks) and `jobs-worker` (jobs, `--keepalive`), one of each, with a restart policy,
  a stop grace period and `messenger:stop-workers` on deploy. No crontab.
- **One stable outbound IP address for KSeF** (ADR [0023](0023-ksef-purchase-invoice-import.md)):
  KSeF rate-limits per pair (NIP, client IP).
- **Secrets per environment**: the JWT keypair and passphrase, `APP_SECRET`, the database password,
  the SMTP credentials (ADR 0012) and the keyring `SECRETS_ENCRYPTION_KEYS`, which must never be in
  the image or the repository and must be backed up apart from the database
  (ADR [0026](0026-per-organization-secrets-encrypted-with-app-keyring.md) point 3).
- **Per-environment URLs**: `ADMIN_URL` and `TIMECLOCK_URL` for e-mailed links
  (ADR [0013](0013-member-access-by-one-time-email-link.md)), and the admin's build-time
  `VITE_TIMECLOCK_URL` (ADR [0021](0021-admin-for-owners-and-managers.md)).
- **EU region**: the data includes employees' personal data, and payroll data comes later.

Scale and budget: for now only the owner (who is also the developer) uses the system; one bar comes
next, a few organizations later. The budget is "as little as possible until there are real users".

The repository is not production-ready yet: `api/Dockerfile` has a `prod` target, but its entrypoint
installs Composer dependencies when `vendor/` is missing, generates a JWT keypair when none exists and
runs migrations on every start; the build context is `api/`, so the SPAs can't be built into the
image; and the Caddyfile serves only `api/public`.

Options compared in November 2026 (production only, per month, without VAT; list prices from the
providers' price lists or catalogs, or third-party summaries of them):

| Option | Cost | Backups | Notes |
|---|---|---|---|
| OVHcloud Public Cloud d2-2, WAW1, Compose | ≈ €8 | ours | Warsaw; French company, outside the US CLOUD Act |
| OVHcloud d2-4, WAW1, Compose | ≈ €14 | ours | 4 GB |
| Hetzner Cloud CX23 (DE), Compose | ≈ €14 | ours | German company |
| OVHcloud instance + managed PostgreSQL 17 | ≈ €70 / €145–175 | 2 days (Essential) / 14 days (Business) | 4–10× the server |
| AWS EC2 t4g.small + RDS PostgreSQL 17 (eu-central-1) | ≈ $37 | point-in-time, 7–35 days | US company, CLOUD Act |
| AWS EC2 t4g.medium, PostgreSQL on the VM | ≈ $36 | ours | same price as EC2 + RDS |
| AWS ECS Fargate + RDS + load balancer + NAT gateway | ≈ $125–135 | point-in-time | NAT gateway only for a stable outbound IP |

## Decision

1. **One OVHcloud Public Cloud instance in Warsaw (region WAW1) runs both staging and production.**
   Flavour **d2-2** (Discovery: 1 vCore, 2 GB RAM, 25 GB local NVMe, 100 Mbit/s): €5.71 a month on
   monthly billing or €0.0104 an hour; its public IPv4, billed separately since 1 October 2026, is
   €0.0027 an hour (≈ €1.97 a month). Prices without VAT from OVHcloud's public catalog on
   23 November 2026. If WAW1 has no d2-2 to order, the instance goes to the nearest EU region that
   has one (DE1 Frankfurt or GRA). A current Debian or Ubuntu LTS image with Docker Engine and the
   Compose plugin; the provisioning issue fixes the details (SSH keys only, a firewall allowing 22,
   80 and 443, unattended security updates).

2. **Staging and production are two Compose projects from the same production Compose file**, with
   one env file each: `business-tool-production` and `business-tool-staging`. Each runs, from the
   same API image tag:
   - `php`: FrankenPHP, plain HTTP on the projects' shared internal network, serving both SPAs and
     `/api` (point 4);
   - `worker`: `messenger:consume async scheduler_default`;
   - `jobs-worker`: `messenger:consume jobs --keepalive`, `stop_grace_period: 60s`.

   Both workers have `restart: unless-stopped` and `--time-limit`/`--memory-limit`. Each stage has
   its own database, database role, secrets, JWT keypair, keyring, domains, mail settings and
   `KSEF_API_URL` (TEST or DEMO for staging, PROD for production; ADR 0023 point 1).

3. **One edge Caddy owns ports 80 and 443** (a third Compose project, `business-tool-edge`, with
   the PostgreSQL server of point 5). It gets Let's Encrypt certificates for all app domains and
   proxies each domain to its stage's `php` service by host name. The stages' FrankenPHP never
   listens on a public port. Symfony trusts the edge: `framework.trusted_proxies` comes from
   `TRUSTED_PROXIES` (the internal network's range), with the `X-Forwarded-For`, `-Proto` and `-Host`
   headers, so that the rate limits of ADR 0013 see the client's IP and the refresh cookie is
   `Secure`.

4. **Each app is served with its API from one origin**, as ADR 0020 requires. The production
   image contains both SPA builds next to the API, and its Caddyfile has one site per app: the
   SPA's static files, with `index.html` as fallback for client-side routes, and `/api` (plus the
   assets of `/api/docs`) passed to PHP. The domains are placeholders until a domain is bought:

   | | Admin | Time clock (PWA) |
   |---|---|---|
   | Production | `admin.<domain>` | `app.<domain>` |
   | Staging | `admin.staging.<domain>` | `app.staging.<domain>` |

   The time clock's service worker then has `/` on its own HTTPS origin as its scope, and each app
   keeps its own `localStorage` and refresh cookie (ADR 0008, ADR 0020). No other origin calls the
   API: `CORS_ALLOW_ORIGIN` lists the stage's two origins only.

5. **One PostgreSQL 17 server for both stages**, with one database and one role per stage. Each role
   owns only its own database and has no `CONNECT` on the other; neither is a superuser. Data lives in
   a named volume on the instance's disk. The server is tuned for the small host (for example
   `shared_buffers=128MB`, `max_connections` around 50).

6. **Fitting two stacks into 2 GB of memory.** The estimate, to be checked on the real host:

   | Process | Memory |
   |---|---|
   | OS, Docker, edge Caddy | ≈ 300 MB |
   | PostgreSQL (shared) | ≈ 200–250 MB |
   | Per stage: FrankenPHP (few threads) | ≈ 150–200 MB |
   | Per stage: `worker` + `jobs-worker` | ≈ 2 × 80 MB |
   | Total, both stages | ≈ 1.2–1.4 GB |

   To keep it there:
   - Every service has a Compose `mem_limit`, so a runaway process is killed in its own container
     and not the database.
   - FrankenPHP runs with a small, fixed number of PHP threads.
   - The workers run with `--memory-limit=128M`.
   - The host has a 2 GB swap file as a cushion, not as working memory.
   - Staging is started only when needed (`docker compose -p business-tool-staging stop` otherwise),
     and its `jobs-worker` can stay off.
   - On the 25 GB disk, Docker's `json-file` logs are rotated (`max-size`, `max-file`), old images
     are pruned after each deploy, and backups go off the host (point 9).

   If this does not hold (point 11), the next flavour is d2-4 (4 GB, €11.44 a month).

7. **Images are built in CI, never on the host.** The production image is built from the repository
   root, so that it contains the API and both SPA builds, and pushed to GitHub Container Registry. The
   admin bundle embeds `VITE_TIMECLOCK_URL` at build time (ADR 0021), so the image is built once per
   stage from the same commit. Its entrypoint does not install dependencies, generate keys or run
   migrations: the JWT keypair comes from the stage's secrets, and migrations are an explicit deploy
   step.

8. **Deploys run from GitHub Actions over SSH** (details in #36):
   - Staging deploys every push to `main`.
   - Production deploys a `v*` tag through the `production` GitHub Environment, which requires the
     owner's approval.
   - A deploy pulls the image, runs `doctrine:migrations:migrate --all-or-nothing` in a one-off
     container, recreates the stage's services, and then runs `messenger:stop-workers`.

9. **Secrets are kept in GitHub Environments (`staging`, `production`) and written to the host by
   the deploy.** The deploy writes them to `/srv/business-tool/<stage>/.env` (readable by root only)
   and writes the JWT keypair to files mounted read-only into the containers.

   | Kind | Variables |
   |---|---|
   | Secrets | `APP_SECRET`, `DATABASE_URL` (with the password), `JWT_PASSPHRASE` with the keypair, `MAILER_DSN`, `SECRETS_ENCRYPTION_KEYS` |
   | Configuration | `DEFAULT_URI`, `ADMIN_URL`, `TIMECLOCK_URL`, `CORS_ALLOW_ORIGIN`, `TRUSTED_PROXIES`, `MAILER_FROM`, `KSEF_API_URL`, `JWT_TOKEN_TTL` |

   GitHub secrets can't be read back, so the owner keeps the authoritative copy of each production
   secret in their password manager. That includes the keyring and the JWT keypair, which are thereby
   backed up apart from the database (ADR 0026 point 3).

   OVHcloud's Secret Manager is not used for now; the instance would need its own credentials to
   read it.

10. **The instance's public IPv4 is the outbound address for KSeF.** It stays the same for as long as
    the instance exists. Both stages share it; staging only ever calls KSeF TEST or DEMO. An address
    that must survive a new instance is an OVHcloud Additional IP, or a Floating IP behind a Gateway
    (size S: €2 a month).

11. **Backups (#37) go to OVHcloud Object Storage in another EU region** (Standard class: €0.007 per
    GB-month, no egress fees):
    - **Production:** a nightly `pg_dump` of the database, encrypted before upload, kept 30 days, with
      a tested restore.
    - **Staging:** may go without.
    - **Point-in-time restore:** continuous WAL archiving (pgBackRest or WAL-G, chosen in #37) to the
      same storage, required before the first bar's real data (point 13).
    - **Instance snapshots** (€0.011 per GB-month) are optional and not a database backup.
    - **Secrets** are never in these backups (point 9).

12. **Mail goes through an SMTP relay on port 587**, as ADR 0012 decided. The provider is picked from
    ADR 0012's shortlist when the domain is set up, together with SPF, DKIM and DMARC; Brevo (France)
    is the default choice.

13. **This is a deliberately minimal start, for as long as only the owner uses it.**
    - **When to move on.** Any one of these is enough:
      - **real users:** the first bar is onboarded (#39);
      - **payroll data:** it is stored for real;
      - **memory pressure:** swap is in steady use, or the out-of-memory killer stops a service;
      - **isolation:** a staging deploy or experiment affects production, or a security review asks
        for it.
    - **Next steps,** in order of cost:
      1. a bigger flavour (d2-4, or b3-8 with dedicated vCores);
      2. a separate production instance, with staging staying on the d2-2 or going;
      3. continuous WAL archiving at the latest when payroll data arrives;
      4. a managed PostgreSQL 17 when its cost is justified: OVHcloud Business (14-day backups), or
         AWS EC2 + RDS in eu-central-1 (point-in-time restore for 7–35 days).

    The Compose file, the image and the pipeline stay the same through these steps; only the host
    and the database's address change.

## Alternatives considered

- **Hetzner Cloud CX23 in Germany** (≈ €6 for 2 vCPU and 4 GB, ≈ €14 with backups and Object
  Storage). The closest alternative in cost and shape, also outside the CLOUD Act. OVHcloud was
  preferred for the Warsaw location, next to the first customer.
- **An OVHcloud VPS in Warsaw** (VPS-1, €6.49 a month). The VPS range had availability problems in
  the Polish zone when this was decided; Public Cloud instances are billed hourly and can be resized.
- **OVHcloud managed PostgreSQL 17** (available in WAW, versions 15–18). Essential is one node at
  ≈ $64 a month with 2 days of backups and no SLA; Business is two nodes at ≈ $128–164 with 14 days.
  4–10 times the server's price, for a system only the owner uses. Kept as a next step (point 13).
- **AWS EC2 t4g.small + RDS PostgreSQL 17 in eu-central-1** (≈ $37 a month, point-in-time restore
  for 7–35 days, an Elastic IP for KSeF). The best managed option, but about four times the cost, and
  AWS is a US company under the CLOUD Act (with its DPA and SCCs). Running PostgreSQL on a 4 GB EC2
  instance instead costs the same (≈ $36), so on AWS the database would be RDS. Kept as a next step.
- **AWS ECS Fargate + RDS** (≈ $125–135 a month): a load balancer and a NAT gateway (the only way to
  a stable outbound IP) cost more than the application. AWS App Runner stopped taking new customers on
  30 April 2026. Lightsail containers have no stable outbound IP, and Lightsail's managed databases
  offer PostgreSQL up to 16 only.
- **Scaleway** (a VM with managed PostgreSQL, ≈ €22–24), **Render** (≈ $22–25 a month per
  stage, a US PaaS with platform lock-in) and **Fly.io** (managed PostgreSQL from $38). More
  expensive at this scale, and none fits better.
- **mikr.us** (≈ €3 a month for 4 GB, in Helsinki). No dedicated IPv4 even as a paid extra, so port
  443 for our domains only through its proxy or a CDN, and a shared outbound address for KSeF. No SLA,
  and no GDPR processing agreement found.
- **SPAs on a static host or CDN, API on its own domain.** Breaks ADR 0020's same-origin cookie
  unless the CDN proxies `/api`. Another provider in the request path, with no gain at this scale.
- **A PostgreSQL container per stage.** Better isolation, but another 150–250 MB on a 2 GB host.
  Separate servers come with separate instances (point 13).
- **A separate instance for staging** (another ≈ €8 a month). Not needed while one person uses
  both; it is one of the next steps.
- **FrankenPHP of one stage terminating TLS for both.** It couples the stages: a broken production
  deploy would take staging's certificates down with it. The edge Caddy is small (≈ 30 MB) and stays
  the same when production moves.
- **Secrets in a provider's secret store** (OVHcloud Secret Manager, AWS SSM). A credential for the
  store on the host and another dependency, for the same handful of values that GitHub Environments
  already hold.

## Consequences

- **Cost** is about €8 a month without VAT: €5.71 for the instance and ≈ €1.97 for the IPv4,
  plus cents for Object Storage and any snapshots.
- **Staging and production share one machine**: CPU, memory, disk, the PostgreSQL server and the IP
  address. A heavy staging deploy or job slows production, and a PostgreSQL restart or upgrade takes
  both down. Accepted while only the owner uses the system; point 13 names when that ends.
- **No high availability**: one instance in a one-zone region. If the instance is lost, a new one is
  provisioned and the database restored from Object Storage, which takes hours, and data since the
  last nightly dump is lost until WAL archiving is in place.
- **We run the host**: OS updates, Docker, the firewall, disk and memory. The provisioning issue
  writes this down as code or as a runbook.
- **GDPR**: OVH SAS (France) with its EU data processing agreement; data in Warsaw, backups in
  another EU region; no US provider in the data path. The mail provider is a sub-processor of its
  own (point 12).
- **Answers to earlier ADRs:**
  - the production run of the workers (ADR 0012 point 5, ADR 0025 point 8): point 2;
  - where the keyring lives (ADR 0026 point 3): point 9;
  - the per-environment URLs (ADR 0013, ADR 0021): points 4 and 7;
  - the stable KSeF address (ADR 0023): point 10;
  - the same-origin layout ADR 0020 requires: points 3 and 4.

  The mail provider of ADR 0012 point 2 is chosen with the domain.
- **Follow-up work:**
  - the production image, entrypoint, Caddyfiles and Compose file (points 2–7);
  - provisioning the instance (point 1);
  - buying the domain and setting up DNS and mail (owner);
  - the deploy pipeline (#36);
  - the backups and restore drill (#37);
  - error tracking and uptime (#38).
