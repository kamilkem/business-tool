#!/bin/sh
# One database and one role per stage (adr/0035 §5). Each role owns only its own database, cannot
# connect to the other one and is not a superuser. Runs once, when the data directory is empty.
set -eu

for stage in production staging; do
	role="business_tool_${stage}"
	eval "password=\${$(echo "$stage" | tr '[:lower:]' '[:upper:]')_DB_PASSWORD}"
	psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
		-v role="$role" -v password="$password" <<'SQL'
CREATE ROLE :"role" LOGIN PASSWORD :'password';
CREATE DATABASE :"role" OWNER :"role";
REVOKE CONNECT, TEMPORARY ON DATABASE :"role" FROM PUBLIC;
SQL
done

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<'SQL'
REVOKE CONNECT, TEMPORARY ON DATABASE postgres FROM PUBLIC;
SQL
