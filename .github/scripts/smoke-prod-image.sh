#!/usr/bin/env bash
# Smoke test of the production image (adr/0035 §4, §7): starts it with throwaway configuration and
# keys, without a database, and checks what a broken image or Caddyfile would break first:
#   - the entrypoint refuses to start without the JWT keypair;
#   - each app host serves its SPA, with index.html for client-side routes;
#   - /api on each app host reaches Symfony (the OpenAPI document needs no database);
#   - hashed assets are cached for a year, the entry point and the service worker are revalidated.
# Usage: smoke-prod-image.sh [image]   (default business-tool-api:prod; `make prod-smoke`)
set -euo pipefail

image="${1:-business-tool-api:prod}"
name="business-tool-smoke-$$"
port="${SMOKE_PORT:-8099}"
admin_host=admin.smoke.test
timeclock_host=app.smoke.test
work="$(mktemp -d)"
failures=0

cleanup() {
	docker rm -f "$name" >/dev/null 2>&1 || true
	rm -rf "$work"
}
trap cleanup EXIT

fail() {
	echo "FAIL: $*" >&2
	failures=$((failures + 1))
}

pass() {
	echo "ok: $*"
}

passphrase=smoke-only
mkdir -p "$work/jwt"
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -aes256 -pass "pass:$passphrase" -out "$work/jwt/private.pem"
openssl pkey -in "$work/jwt/private.pem" -passin "pass:$passphrase" -pubout -out "$work/jwt/public.pem"
chmod 644 "$work/jwt/"*.pem

cat >"$work/app.env" <<EOF
APP_SECRET=$(openssl rand -hex 32)
DATABASE_URL=postgresql://smoke:smoke@127.0.0.1:5432/smoke?serverVersion=17&charset=utf8
JWT_PASSPHRASE=$passphrase
MAILER_DSN=null://null
MAILER_FROM=Smoke <no-reply@smoke.test>
SECRETS_ENCRYPTION_KEYS=k1:$(openssl rand -base64 32)
ADMIN_HOST=$admin_host
TIMECLOCK_HOST=$timeclock_host
ADMIN_URL=https://$admin_host
TIMECLOCK_URL=https://$timeclock_host
DEFAULT_URI=https://$admin_host
CORS_ALLOW_ORIGIN=^https://(admin|app)\\.smoke\\.test\$
TRUSTED_PROXIES=private_ranges
KSEF_API_URL=https://api-test.ksef.mf.gov.pl/v2
EOF

# 1. Without the keypair the entrypoint must refuse to start.
if docker run --rm --env-file "$work/app.env" "$image" >"$work/nokeys.log" 2>&1; then
	fail "the image started without config/jwt/*.pem"
elif grep -q 'Missing config/jwt/private.pem' "$work/nokeys.log"; then
	pass "refuses to start without the JWT keypair"
else
	fail "unexpected output without the JWT keypair: $(tail -3 "$work/nokeys.log")"
fi

# 2. The workers' and the deploy's path: bin/console runs with the stage's configuration.
if docker run --rm --env-file "$work/app.env" "$image" bin/console about >"$work/console.log" 2>&1 && grep -q 'prod' "$work/console.log"; then
	pass "bin/console runs in prod"
else
	fail "bin/console about failed: $(tail -5 "$work/console.log")"
fi

# 3. Start it properly.
docker run -d --name "$name" --env-file "$work/app.env" -v "$work/jwt:/app/config/jwt:ro" -p "127.0.0.1:$port:80" "$image" >/dev/null
for _ in $(seq 1 60); do
	if curl -fsS -o /dev/null -H "Host: $admin_host" "http://127.0.0.1:$port/" 2>/dev/null; then
		break
	fi
	sleep 1
done

request() { # host path [accept] -> writes headers and body to $work/h and $work/b, prints the status
	curl -sS -o "$work/b" -D "$work/h" -w '%{http_code}' -H "Host: $1" -H "Accept: ${3:-application/json}" "http://127.0.0.1:$port$2"
}
header() { # name -> value of the last response's header
	grep -i "^$1:" "$work/h" | head -1 | cut -d' ' -f2- | tr -d '\r'
}

for host in "$admin_host" "$timeclock_host"; do
	status=$(request "$host" /)
	if [ "$status" = 200 ] && grep -q '<div id="root">' "$work/b"; then pass "$host / serves the SPA"; else fail "$host / answered $status"; fi
	[ "$(header Cache-Control)" = no-cache ] && pass "$host / is revalidated" || fail "$host / Cache-Control: $(header Cache-Control)"

	status=$(request "$host" /some/client/route)
	if [ "$status" = 200 ] && grep -q '<div id="root">' "$work/b"; then pass "$host client-side route falls back to index.html"; else fail "$host /some/client/route answered $status"; fi

	asset=$(grep -o '/assets/[^"]*\.js' "$work/b" | head -1)
	status=$(request "$host" "$asset")
	if [ "$status" = 200 ] && [ "$(header Cache-Control)" = 'public, max-age=31536000, immutable' ]; then pass "$host $asset is immutable"; else fail "$host $asset answered $status, Cache-Control: $(header Cache-Control)"; fi

	status=$(request "$host" /api/docs.jsonopenapi application/vnd.openapi+json)
	if [ "$status" = 200 ] && grep -q '"openapi"' "$work/b"; then pass "$host /api reaches Symfony"; else fail "$host /api/docs.jsonopenapi answered $status: $(head -c 300 "$work/b")"; fi

	status=$(request "$host" /api/no-such-route)
	if [ "$status" = 404 ] && [[ "$(header Content-Type)" == application/* ]]; then pass "$host unknown /api route is Symfony's 404, not the SPA"; else fail "$host /api/no-such-route answered $status ($(header Content-Type))"; fi
done

status=$(request "$timeclock_host" /sw.js)
if [ "$status" = 200 ] && [ "$(header Cache-Control)" = no-cache ]; then pass "service worker is revalidated"; else fail "/sw.js answered $status, Cache-Control: $(header Cache-Control)"; fi

status=$(request unknown.smoke.test /)
[ "$status" != 200 ] && pass "an unknown host gets no app ($status)" || fail "an unknown host was served"

if [ "$failures" -gt 0 ]; then
	echo "--- container log" >&2
	docker logs "$name" 2>&1 | tail -40 >&2
	echo "$failures check(s) failed" >&2
	exit 1
fi
echo "Production image smoke test passed."
