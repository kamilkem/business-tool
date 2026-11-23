# Provisioning the host

The one OVHcloud Public Cloud instance of [ADR 0035](../../adr/0035-staging-and-production-on-one-ovh-instance.md)
([#212](https://github.com/kamilkem/business-tool/issues/212)). Done once; repeat it to rebuild the host
from scratch (then restore the database, #37).

| File | What |
|---|---|
| [`cloud-init.yaml`](cloud-init.yaml) | First-boot setup: SSH keys only, `deploy` user, firewall (22/80/443), security updates, 2 GB swap, Docker + Compose, `/srv/business-tool/*` |
| [`generate-secrets.sh`](generate-secrets.sh) | Every secret of the edge and both stages, generated once on your machine |

## 1. Create the instance (OVHcloud Control Panel)

Public Cloud → your project → **Instances** → **Create an instance**:

| Setting | Value |
|---|---|
| Model | **Discovery d2-2** (1 vCore, 2 GB, 25 GB NVMe): €5.71 a month on monthly billing, or €0.0104 an hour |
| Region | **Warsaw (WAW1)**. If d2-2 isn't offered there, Frankfurt (DE1) or Gravelines (GRA) |
| Image | **Debian 13** (or Ubuntu 24.04 LTS) |
| SSH key | your own public key (for example `~/.ssh/id_ed25519.pub`) |
| Network | public network only (the public IPv4 is billed at €1.97 a month) |
| Post-installation script | the whole content of `deploy/host/cloud-init.yaml` |
| Name | `business-tool-1` |
| Automatic backup | off (database backups are #37) |
| Billing | monthly once you keep it; hourly while trying |

Keep the instance: its public IPv4 is the outbound address KSeF sees (ADR 0035 §10), and deleting the
instance gives it up.

## 2. Check it

After 3–5 minutes (the default user is `debian` on Debian, `ubuntu` on Ubuntu):

```bash
ssh debian@<ip> 'cloud-init status --wait && sudo business-tool-host-check'
```

Every line should say `ok`. The check also prints the public IPv4: keep it in your password manager
next to the secrets, and use it for the DNS records of [#213](https://github.com/kamilkem/business-tool/issues/213).

## 3. Generate the secrets (on your machine)

```bash
deploy/host/generate-secrets.sh ~/business-tool-secrets
```

It prints nothing and refuses to overwrite. Store the folder's files in your password manager:
`edge.env`, and for `production` and `staging` their `secrets.env` and `jwt/` keypair. Delete the local
folder once the deploy pipeline (#36) has put the stages' secrets into their GitHub Environments. The password manager's copy is the one that counts: GitHub Environment secrets can't be
read back, and the keyring must survive apart from the database (ADR 0026 §3).

## 4. Start the database

Copy the edge project to the host and start only PostgreSQL. Caddy waits for the domain (#213),
because it would keep asking Let's Encrypt for certificates of hosts that don't exist yet.

```bash
scp -r deploy/edge debian@<ip>:/tmp/edge
ssh debian@<ip>
```

On the host (`/srv/business-tool` belongs to `deploy`, so Compose runs as that user):

```bash
sudo cp -r /tmp/edge/. /srv/business-tool/edge/ && rm -rf /tmp/edge
sudo cp /srv/business-tool/edge/.env.example /srv/business-tool/edge/.env
sudo nano /srv/business-tool/edge/.env    # paste the three passwords from edge.env; hosts stay placeholders until #213
sudo chown -R deploy:deploy /srv/business-tool/edge && sudo chmod 600 /srv/business-tool/edge/.env
alias edge='sudo -u deploy docker compose --project-directory /srv/business-tool/edge -p business-tool-edge'
edge up -d --wait database
edge ps
```

The init scripts create `business_tool_production` and `business_tool_staging`, each with its own role,
the first time only (empty data directory). The apps connect over the Docker network with a password;
inside the database container itself, PostgreSQL's official image trusts local connections.

## 5. Record the baseline

```bash
free -m && sudo docker stats --no-stream
```

Note the numbers in #212. Both stages run once the deploy pipeline exists (#36); if swap is then in
steady use, resize to d2-4 (ADR 0035 §6, §13).

## Later

- The deploy pipeline (#36) adds an SSH key to the `deploy` user, puts each stage's secrets into its
  GitHub Environment and writes `/srv/business-tool/<stage>/app.env` and `jwt/` on each deploy.
- When the domain exists (#213), fill the hosts and `ACME_EMAIL` in the edge's `.env` and start
  Caddy: `edge up -d` (the alias above).
- Reboots are not automatic. After security updates, `/var/run/reboot-required` says when one is due;
  `sudo reboot` restarts both stages (their restart policy brings them back).
