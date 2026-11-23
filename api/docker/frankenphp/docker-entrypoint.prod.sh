#!/bin/sh
# Production entrypoint (adr/0035 §7). Unlike the dev one it installs nothing, generates no keys and
# runs no migrations (the deploy runs them as a separate step); it only refuses to start without the
# configuration the app cannot run without, so a broken deploy fails at once instead of at the first
# request.
set -e

require() {
	for name in "$@"; do
		eval "value=\${$name:-}"
		if [ -z "$value" ]; then
			echo "Missing required environment variable $name (see deploy/app.env.example)." >&2
			exit 1
		fi
	done
}

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	require APP_SECRET DATABASE_URL MAILER_DSN MAILER_FROM DEFAULT_URI ADMIN_URL TIMECLOCK_URL \
		SECRETS_ENCRYPTION_KEYS KSEF_API_URL
fi

if [ "$1" = 'frankenphp' ]; then
	require ADMIN_HOST TIMECLOCK_HOST JWT_PASSPHRASE
	for key in config/jwt/private.pem config/jwt/public.pem; do
		if [ ! -r "$key" ]; then
			echo "Missing $key: mount the stage's JWT keypair read-only at /app/config/jwt (adr/0035 §9)." >&2
			exit 1
		fi
	done
fi

exec docker-php-entrypoint "$@"
