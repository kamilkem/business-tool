#!/usr/bin/env bash
# Checks the production deployment files (deploy/, adr/0035) without a server: both Compose files
# resolve with their example variables, and the edge Caddyfile is valid. Needs Docker.
# Usage: check-deploy-config.sh   (`make deploy-check`)
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/edge" "$work/stage"
cp -R "$root/deploy/edge/." "$work/edge/"
cp "$root/deploy/edge/.env.example" "$work/edge/.env"
cp "$root/deploy/stage/compose.yaml" "$work/stage/"
cp "$root/deploy/stage/.env.example" "$work/stage/.env"
cp "$root/deploy/stage/app.env.example" "$work/stage/app.env"

(cd "$work/edge" && docker compose -p business-tool-edge config --quiet) && echo "ok: deploy/edge/compose.yaml"
(cd "$work/stage" && docker compose -p business-tool-production config --quiet) && echo "ok: deploy/stage/compose.yaml"

caddy_image="$(sed -n 's/^ *image: *\(caddy:[^ ]*\).*/\1/p' "$root/deploy/edge/compose.yaml")"
set -a
. "$work/edge/.env"
set +a
docker run --rm -v "$work/edge/Caddyfile:/etc/caddy/Caddyfile:ro" \
	-e ACME_EMAIL -e PRODUCTION_ADMIN_HOST -e PRODUCTION_TIMECLOCK_HOST -e STAGING_ADMIN_HOST -e STAGING_TIMECLOCK_HOST \
	"$caddy_image" caddy validate --config /etc/caddy/Caddyfile >/dev/null 2>"$work/caddy.log" \
	&& echo "ok: deploy/edge/Caddyfile" \
	|| { cat "$work/caddy.log" >&2; exit 1; }
