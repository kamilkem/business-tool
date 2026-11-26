#!/usr/bin/env bash
# Deploys one stage of adr/0035 to the host over SSH (#36). Runs the same from GitHub Actions
# (.github/workflows/deploy.yaml) and from a workstation (`make deploy`).
#
# Usage: deploy/deploy.sh <staging|production>
#
# Reads:
#   deploy/stage/<stage>.env      the stage's configuration (hosts, KSeF, sender); in the repository
#   API_IMAGE                     the image to run, e.g. ghcr.io/kamilkem/business-tool-api:staging-<sha>
#   DEPLOY_HOST                   the host (default 54.37.131.250), reached as DEPLOY_USER (default deploy)
#   DEPLOY_SSH_KEY_FILE           optional: the deploy key (CI puts it in ~/.ssh/id_ed25519)
#   the stage's secrets, either as environment variables (CI: the stage's GitHub Environment)
#     APP_SECRET DATABASE_URL JWT_PASSPHRASE SECRETS_ENCRYPTION_KEYS MAILER_DSN
#     JWT_PRIVATE_KEY JWT_PUBLIC_KEY (PEM contents)
#   or from SECRETS_DIR (a workstation: the folder of deploy/host/generate-secrets.sh, with
#     <stage>/secrets.env and <stage>/jwt/{private,public}.pem)
#   REGISTRY_USER and REGISTRY_TOKEN  optional: log the host in to ghcr.io for the pull, then out again
#   SKIP_PULL=1                   optional: the image is already on the host (a local test)
#
# Steps: write compose.yaml, .env, app.env (mode 600) and jwt/ into /srv/business-tool/<stage>; pull;
# run the migrations once in a one-off container; start the stage; restart both workers so they run the
# new code (they finish the current message first); wait until `php` is healthy; prune images older
# than three days; then check over HTTPS that both apps answer and that /api reaches the database.
# Nothing secret is printed.
set -euo pipefail

stage="${1:-}"
case "$stage" in
	staging | production) ;;
	*)
		echo "Usage: $0 <staging|production>" >&2
		exit 2
		;;
esac

root="$(cd "$(dirname "$0")/.." && pwd)"
host="${DEPLOY_HOST:-54.37.131.250}"
user="${DEPLOY_USER:-deploy}"
project="business-tool-$stage"
remote_dir="/srv/business-tool/$stage"

set -a && . "$root/deploy/stage/$stage.env" && set +a

if [ -n "${SECRETS_DIR:-}" ]; then
	set -a
	. "$SECRETS_DIR/$stage/secrets.env"
	set +a
	JWT_PRIVATE_KEY="$(cat "$SECRETS_DIR/$stage/jwt/private.pem")"
	JWT_PUBLIC_KEY="$(cat "$SECRETS_DIR/$stage/jwt/public.pem")"
fi

missing=()
for name in API_IMAGE ADMIN_HOST TIMECLOCK_HOST KSEF_API_URL MAILER_FROM APP_SECRET DATABASE_URL \
	JWT_PASSPHRASE SECRETS_ENCRYPTION_KEYS MAILER_DSN JWT_PRIVATE_KEY JWT_PUBLIC_KEY; do
	[ -n "${!name:-}" ] || missing+=("$name")
done
if [ ${#missing[@]} -gt 0 ]; then
	echo "Missing: ${missing[*]}" >&2
	exit 1
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
umask 077
mkdir -p "$work/files/jwt"

value() { # name -> 'value' for app.env; refuses what env_file can't carry safely
	local v="${!1}"
	case "$v" in
		*"'"* | *$'\n'*)
			echo "$1 contains a quote or a newline, which app.env can't hold." >&2
			exit 1
			;;
	esac
	printf "%s='%s'\n" "$1" "$v"
}

escape_host() { printf '%s' "$1" | sed 's/\./\\./g'; }

export APP_ENV=prod TRUSTED_PROXIES=private_ranges
export ADMIN_URL="https://$ADMIN_HOST" TIMECLOCK_URL="https://$TIMECLOCK_HOST" DEFAULT_URI="https://$ADMIN_HOST"
export CORS_ALLOW_ORIGIN="^https://($(escape_host "$ADMIN_HOST")|$(escape_host "$TIMECLOCK_HOST"))\$"
export JWT_TOKEN_TTL="${JWT_TOKEN_TTL:-900}"
{
	echo "# Written by deploy/deploy.sh for the $stage stage; edits are overwritten by the next deploy."
	for name in APP_ENV APP_SECRET DATABASE_URL JWT_PASSPHRASE JWT_TOKEN_TTL SECRETS_ENCRYPTION_KEYS \
		MAILER_DSN MAILER_FROM ADMIN_HOST TIMECLOCK_HOST ADMIN_URL TIMECLOCK_URL DEFAULT_URI \
		CORS_ALLOW_ORIGIN TRUSTED_PROXIES KSEF_API_URL; do
		value "$name"
	done
	# Optional: without a key the item interpreter is off and only remembered aliases match items (adr/0042).
	[ -z "${ANTHROPIC_API_KEY:-}" ] || value ANTHROPIC_API_KEY
} >"$work/files/app.env"
printf '%s\n' "$JWT_PRIVATE_KEY" >"$work/files/jwt/private.pem"
printf '%s\n' "$JWT_PUBLIC_KEY" >"$work/files/jwt/public.pem"
printf 'STAGE=%s\nAPI_IMAGE=%s\n' "$stage" "$API_IMAGE" >"$work/files/.env"
install -m 644 "$root/deploy/stage/compose.yaml" "$work/files/compose.yaml"

ssh_opts=(-o BatchMode=yes -o ConnectTimeout=20)
[ -n "${DEPLOY_SSH_KEY_FILE:-}" ] && ssh_opts+=(-i "$DEPLOY_SSH_KEY_FILE" -o IdentitiesOnly=yes)
ssh_() { ssh "${ssh_opts[@]}" "$user@$host" "$@"; }

echo "→ $stage: copying configuration to $host:$remote_dir"
# macOS tar would add its own metadata headers, which GNU tar on the host warns about.
tar_opts=()
tar --version 2>/dev/null | grep -q bsdtar && tar_opts=(--no-mac-metadata --no-xattrs)
tar "${tar_opts[@]}" -C "$work/files" -cf - . | ssh_ "mkdir -p '$remote_dir' && tar -C '$remote_dir' --no-same-owner -xf - && chmod 600 '$remote_dir/app.env' '$remote_dir/jwt/private.pem'"

echo "→ $stage: pulling, migrating, starting $API_IMAGE"
{
	echo "set -euo pipefail"
	echo "cd '$remote_dir'"
	echo "dc() { docker compose -p '$project' \"\$@\"; }"
	if [ "${SKIP_PULL:-}" != 1 ]; then
		if [ -n "${REGISTRY_TOKEN:-}" ]; then
			printf "docker login ghcr.io -u '%s' --password-stdin >/dev/null <<'TOKEN'\n%s\nTOKEN\n" "${REGISTRY_USER:-github}" "$REGISTRY_TOKEN"
		fi
		echo "dc pull --quiet"
		[ -n "${REGISTRY_TOKEN:-}" ] && echo "docker logout ghcr.io >/dev/null"
	fi
	cat <<'REMOTE'
dc run --rm --no-deps -T php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing </dev/null
dc up -d --remove-orphans --quiet-pull
# The workers run the new code only after a restart; on SIGTERM they finish the current message
# (stop_grace_period in compose.yaml), as messenger:stop-workers would make them.
dc up -d --no-deps --force-recreate worker jobs-worker
for _ in $(seq 1 60); do
	[ "$(docker inspect -f '{{.State.Health.Status}}' "$(dc ps -q php)")" = healthy ] && break
	sleep 2
done
docker inspect -f '{{.State.Health.Status}}' "$(dc ps -q php)" | grep -qx healthy || { dc logs --tail 50 php; exit 1; }
dc ps --format '{{.Service}}: {{.Status}}'
docker image prune -af --filter until=72h >/dev/null
REMOTE
} >"$work/remote.sh"
# Sent as a file and run from it: a command reading stdin (compose run, exec) must not swallow the
# rest of the script. The file is mode 600 and removed afterwards (it may hold the registry token).
ssh_ 'f=$(mktemp) && cat >"$f" && bash "$f"; rc=$?; rm -f "$f"; exit $rc' <"$work/remote.sh"

echo "→ $stage: checking https://$ADMIN_HOST and https://$TIMECLOCK_HOST"
failures=0
check() { # description, command
	for _ in $(seq 1 20); do
		if eval "$2"; then
			echo "ok: $1"
			return
		fi
		sleep 3
	done
	echo "FAIL: $1" >&2
	failures=$((failures + 1))
}
for app_host in "$ADMIN_HOST" "$TIMECLOCK_HOST"; do
	check "$app_host serves its app" "curl -fsS --max-time 10 https://$app_host/ | grep -q '<div id=\"root\">'"
	# A login with unknown credentials reaches PHP and the database and is refused: 401.
	check "$app_host/api reaches the database" "[ \"\$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -H 'Content-Type: application/json' -d '{\"email\":\"deploy-check@invalid.test\",\"password\":\"x\"}' https://$app_host/api/auth/login)\" = 401 ]"
done
if [ "$failures" -gt 0 ]; then
	echo "$stage deploy finished, but $failures check(s) failed." >&2
	exit 1
fi
echo "$stage is running $API_IMAGE."
