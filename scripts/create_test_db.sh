#!/usr/bin/env bash
set -euo pipefail

# Script to create the isolated MariaDB database used by the PHPUnit suite (see phpunit.xml).
# Idempotent: safe to run on every machine/volume; it never touches the development database.
DB_CONTAINER="${DB_CONTAINER:-fahares_mariadb}"
TEST_DB="${TEST_DB:-fahares_test}"

echo "Creating test database '${TEST_DB}' in container '${DB_CONTAINER}'..."

# Root password and app user are read from the DB container's own environment, so no secrets pass through the host.
docker exec -i -e TEST_DB="$TEST_DB" "$DB_CONTAINER" sh -c '
    mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "
        CREATE DATABASE IF NOT EXISTS \`$TEST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        GRANT ALL PRIVILEGES ON \`$TEST_DB\`.* TO \`$MARIADB_USER\`@\`%\`;
        FLUSH PRIVILEGES;
    "
'

echo "Test database '${TEST_DB}' is ready."
