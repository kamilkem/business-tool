# Deployment files

How staging and production run is decided in [ADR 0035](../adr/0035-staging-and-production-on-one-ovh-instance.md):
one OVHcloud instance, three Compose projects. This folder holds their files. Provisioning the host
is [`host/README.md`](host/README.md) (#212); the pipeline that ships images and runs these steps is
[#36](https://github.com/kamilkem/business-tool/issues/36).

| On the host | From | What |
|---|---|---|
| `/srv/business-tool/edge/` | `edge/` | Caddy on 80/443 (TLS for every app host) and PostgreSQL 17 with a database and role per stage; owns the `business-tool` network |
| `/srv/business-tool/production/` | `stage/` | `php` (API + both SPAs), `worker`, `jobs-worker` of the production image |
| `/srv/business-tool/staging/` | `stage/` | the same for staging |

Each folder has a `.env` with its Compose variables (from `.env.example`); a stage also has `app.env`
(from `app.env.example`, root-only) and `jwt/` with its keypair. Real values live in the stage's
GitHub Environment and in the owner's password manager, never in the repository.

## Deploys

`.github/workflows/deploy.yaml` (#36) deploys:

- **staging** after CI passes for a push to `main`;
- **production** for a `v*` tag on `main` whose CI passed, once the owner approves it in the
  `production` GitHub Environment (`git tag v0.1.0 && git push origin v0.1.0`);
- either stage from the Actions tab (*Run workflow*).

Each run builds the stage's image (`ghcr.io/kamilkem/business-tool-api:<stage>-<commit>`), smoke-tests
it, pushes it, and runs [`deploy.sh`](deploy.sh) over SSH as `deploy`:

1. writes `compose.yaml`, `.env`, `app.env` (mode 600, from [`stage/<stage>.env`](stage) plus the
   stage's secrets) and `jwt/` into `/srv/business-tool/<stage>`;
2. pulls the image and runs `doctrine:migrations:migrate --all-or-nothing` once, in a one-off container;
3. starts the stage and restarts both workers so they run the new code (they finish the current message
   first; this is what `messenger:stop-workers` is for elsewhere);
4. waits for `php` to be healthy, prunes images older than three days;
5. checks over HTTPS that both apps answer and that a login with unknown credentials gets `401` (PHP and
   the database work). A failed check fails the job.

The edge project (`edge/`) is not part of a deploy: it changes rarely and by hand
([`host/README.md`](host/README.md)).

**From a workstation**, with the secrets folder of `host/generate-secrets.sh` and the deploy key:

```bash
make deploy STAGE=staging API_IMAGE=ghcr.io/kamilkem/business-tool-api:staging-<commit> SECRETS_DIR=$HOME/business-tool-secrets
```

**Staging can be stopped** when not in use: `docker compose -p business-tool-staging stop` in
`/srv/business-tool/staging` (as `deploy`).

## A new organization

There is no sign-up (ADR 0021): each organization, with its first owner, is created on the host with
`app:organization:create` (#219). A new owner gets an e-mail with a one-time link to set their
password and lands in the admin; the command also prints that link, to pass on to the owner only
when the e-mail does not arrive (ADR 0037). An existing user simply becomes the owner. Without options it asks
for what it needs and shows a summary before creating anything.

```bash
ssh deploy@54.37.131.250
cd /srv/business-tool/production   # or staging
docker compose -p business-tool-production exec php bin/console app:organization:create \
  --owner-email=owner@example.com --owner-first-name=… --owner-last-name=… --owner-language=pl \
  --name="…" --country=PL --currency=PLN --timezone=Europe/Warsaw --tax-id=…
```

## One-off tasks after a deploy

Some releases need a console command run once per environment after they are deployed. Running one
again is safe; it only queues jobs for the `jobs-worker`, so the command returns at once.

- **KSeF invoice items** (#230): invoices imported from KSeF before items were kept (#229) get them
  from their stored XML. Run once on staging and once on production after the deploy that brings it:

  ```bash
  docker compose -p business-tool-production exec php bin/console app:ksef:read-invoice-items
  ```

  `--organization=<id>` limits it to one organization. The worker's log shows how many invoices got
  items and how many were skipped.

- **Purchased items** (#240): Purchasing keeps its own record of each invoice item, written as invoices
  are imported. Invoices stored before it existed (their items read by the step above included) are
  copied once. Run it on staging and on production after the deploy that brings it, and after
  `app:ksef:read-invoice-items` has finished, since it copies the items the invoices have by then:

  ```bash
  docker compose -p business-tool-production exec php bin/console app:purchasing:record-existing-items
  ```

  `--organization=<id>` limits it to one organization. Running it again adds nothing. The worker's log
  shows for how many invoices items were recorded.
- **Matching items to products (ADR 0042).** Set `ANTHROPIC_API_KEY` as a secret of the stage's GitHub
  Environment (`gh secret set ANTHROPIC_API_KEY --env staging`, and `--env production`); without it only
  the owner's remembered aliases match items, and **with it item and product names are sent to
  Anthropic**. New items are matched as they are recorded. For the items already recorded, once after
  `app:purchasing:record-existing-items` and the first products exist:

  ```bash
  docker compose -p business-tool-production exec php bin/console app:purchasing:match-items
  ```

  `--organization=<id>` limits it to one organization; `--again` also goes through the unassigned
  items the model found nothing for, for after the catalog has grown. The `jobs-worker`'s log shows how
  many items went through and whether the model was unavailable.

  Items the owner already confirmed by hand before matching existed teach it nothing until aliases are
  learned from them (ADR 0045). Once per environment, **before** `app:purchasing:match-items`:

  ```bash
  docker compose -p business-tool-production exec php bin/console app:purchasing:backfill-aliases
  ```

  `--organization=<id>` limits it to one organization. It changes no item or link, calls no model, and
  creates an alias only for a key that has none, so running it again adds nothing.

## Rollback

Run the workflow again for the previous good commit or tag (*Run workflow* on that ref, or re-run its
old deploy job): the image is rebuilt from that commit. Migrations only go forward, so rolling back
across a migration means fixing forward instead: a new migration, or a revert of the change in a new
commit. Images stay in the registry, so `make deploy` with an older `API_IMAGE` works too.

## Images

`make prod-image TIMECLOCK_URL=https://app.tooltipp.com` (staging: `https://app.staging.tooltipp.com`) builds the image for one stage: the admin bundle
embeds the time clock's address (ADR 0021), so each stage has its own image from the same commit.
`make prod-smoke` starts it without a database and checks both apps, `/api` and the caching headers;
`make deploy-check` validates the files in this folder. CI runs all three.
