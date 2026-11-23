#!/usr/bin/env bash
# Generates every secret the host and both stages need, once (adr/0035 §9, #212). Run it on your own
# machine, not on the server, then keep the folder's contents in your password manager: that copy is
# the one that counts, because GitHub Environment secrets can't be read back and the keyring must be
# backed up apart from the database (adr/0026 §3). Nothing is printed; the values only go to files.
#
# Usage: deploy/host/generate-secrets.sh <new-folder>
#   <folder>/edge.env                    PostgreSQL superuser and the stages' database passwords
#   <folder>/<stage>/secrets.env         APP_SECRET, DATABASE_URL, JWT_PASSPHRASE, SECRETS_ENCRYPTION_KEYS
#   <folder>/<stage>/jwt/{private,public}.pem   the stage's JWT keypair (private key encrypted)
# for <stage> = production and staging. MAILER_DSN comes from the mail provider (#213).
# Needs openssl.
set -euo pipefail

if [ $# -ne 1 ]; then
	echo "Usage: $0 <new-folder>" >&2
	exit 2
fi
out="$1"
if [ -e "$out" ] && [ -n "$(ls -A "$out" 2>/dev/null)" ]; then
	echo "$out exists and is not empty: secrets are generated once and never overwritten." >&2
	exit 1
fi

umask 077
mkdir -p "$out"

random() { # bytes -> URL-safe random string (no characters that need escaping in a DSN or .env)
	openssl rand -base64 "$1" | tr -d '\n=' | tr '+/' '-_'
}

key_id="key-$(date -u +%Y%m%d)"

{
	echo "# Edge project (/srv/business-tool/edge/.env, with the hosts and ACME_EMAIL of deploy/edge/.env.example)."
	echo "POSTGRES_PASSWORD=$(random 32)"
} >"$out/edge.env"

for stage in production staging; do
	mkdir -p "$out/$stage/jwt"
	db_password="$(random 32)"
	passphrase="$(random 32)"
	upper="$(echo "$stage" | tr '[:lower:]' '[:upper:]')"
	echo "${upper}_DB_PASSWORD=$db_password" >>"$out/edge.env"

	openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -aes-256-cbc \
		-pass "pass:$passphrase" -out "$out/$stage/jwt/private.pem" 2>/dev/null
	openssl pkey -in "$out/$stage/jwt/private.pem" -passin "pass:$passphrase" -pubout \
		-out "$out/$stage/jwt/public.pem"

	cat >"$out/$stage/secrets.env" <<EOF
# Secrets of the $stage stage (adr/0035 §9). Goes into its GitHub Environment and, through the deploy,
# into /srv/business-tool/$stage/app.env together with the configuration of deploy/stage/app.env.example.
APP_SECRET=$(openssl rand -hex 32)
DATABASE_URL='postgresql://business_tool_$stage:$db_password@database:5432/business_tool_$stage?serverVersion=17&charset=utf8'
JWT_PASSPHRASE=$passphrase
SECRETS_ENCRYPTION_KEYS=$key_id:$(openssl rand -base64 32 | tr -d '\n')
EOF
done

echo "Secrets written to $out (nothing printed)."
echo "Next: store the folder's files in your password manager, then follow deploy/host/README.md."
