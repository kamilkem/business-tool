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

## Order

1. Edge: `docker compose -p business-tool-edge up -d` in `/srv/business-tool/edge`. The scripts in
   `edge/initdb` create the stages' databases and roles the first time only (empty data directory).
2. A stage, from its folder (`<stage>` is `production` or `staging`):

   ```bash
   docker compose -p business-tool-<stage> pull
   docker compose -p business-tool-<stage> run --rm php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
   docker compose -p business-tool-<stage> up -d
   docker compose -p business-tool-<stage> exec php bin/console messenger:stop-workers
   ```

   Migrations run once per deploy, as their own step; the image's entrypoint never runs them.
3. Staging can be stopped when not in use: `docker compose -p business-tool-staging stop`.

## Images

`make prod-image TIMECLOCK_URL=https://app.tooltipp.com` (staging: `https://app.staging.tooltipp.com`) builds the image for one stage: the admin bundle
embeds the time clock's address (ADR 0021), so each stage has its own image from the same commit.
`make prod-smoke` starts it without a database and checks both apps, `/api` and the caching headers;
`make deploy-check` validates the files in this folder. CI runs all three.
