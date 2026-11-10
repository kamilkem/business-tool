#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	if [ ! -f vendor/autoload_runtime.php ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	if [ ! -f config/jwt/private.pem ]; then
		php bin/console lexik:jwt:generate-keypair --skip-if-exists
	fi

	echo 'Waiting for the database...'
	until php bin/console dbal:run-sql -q 'SELECT 1' >/dev/null 2>&1; do
		sleep 1
	done

	php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
fi

exec docker-php-entrypoint "$@"
