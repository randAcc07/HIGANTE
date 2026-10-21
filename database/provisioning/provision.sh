#!/bin/sh
#
# Prints the SQL that provisions the HIGANTE database and its two users.
# Pipe it into a MariaDB client connected as an administrative user:
#
#   DB_DATABASE=higante \
#   DB_USERNAME=higante_app DB_PASSWORD='...' \
#   DB_MIGRATE_USERNAME=higante_migrate DB_MIGRATE_PASSWORD='...' \
#   sh database/provisioning/provision.sh | mariadb -uroot -p
#
# Optional:
#   DB_USER_HOST       host part of both accounts (default: %)
#   DB_TEST_DATABASE   development only: a database the app user fully owns,
#                      for the test suite
#
# Users:
#   app      SELECT, INSERT, UPDATE, DELETE. Cannot change the schema.
#   migrate  CREATE, ALTER, DROP, INDEX, REFERENCES, plus data access on the
#            `migrations` bookkeeping table only. Cannot read or change app
#            data. MariaDB only grants on tables that exist, so `migrations`
#            is created here, ahead of the first migration; the grant
#            survives `migrate:fresh` dropping and recreating it.
#            Renaming a table needs INSERT on the new name, which can only be
#            granted database-wide; grant it for that deployment and re-run
#            this script afterwards to take it away again.
#
# Safe to re-run: it creates what is missing and resets both users' passwords
# and grants to exactly the above.

set -eu

: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
: "${DB_MIGRATE_USERNAME:?DB_MIGRATE_USERNAME is required}"
: "${DB_MIGRATE_PASSWORD:?DB_MIGRATE_PASSWORD is required}"
DB_USER_HOST="${DB_USER_HOST:-%}"
DB_TEST_DATABASE="${DB_TEST_DATABASE:-}"

# Names are interpolated into SQL, so only plain identifiers are accepted.
for name in "$DB_DATABASE" "$DB_USERNAME" "$DB_MIGRATE_USERNAME" ${DB_TEST_DATABASE:+"$DB_TEST_DATABASE"}; do
    case "$name" in
        *[!A-Za-z0-9_]* | '')
            echo "provision.sh: '$name' must contain only letters, digits and underscores" >&2
            exit 1
            ;;
    esac
done

# Quotes a value as an SQL string literal.
sql_string() {
    printf "'%s'" "$(printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/''/g")"
}

app="'$DB_USERNAME'@$(sql_string "$DB_USER_HOST")"
migrate="'$DB_MIGRATE_USERNAME'@$(sql_string "$DB_USER_HOST")"

cat <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Same structure Laravel creates for its migration repository.
CREATE TABLE IF NOT EXISTS \`$DB_DATABASE\`.\`migrations\` (
  \`id\` int(10) unsigned NOT NULL AUTO_INCREMENT,
  \`migration\` varchar(255) NOT NULL,
  \`batch\` int(11) NOT NULL,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS $app IDENTIFIED BY $(sql_string "$DB_PASSWORD");
ALTER USER $app IDENTIFIED BY $(sql_string "$DB_PASSWORD");
REVOKE ALL PRIVILEGES, GRANT OPTION FROM $app;
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB_DATABASE\`.* TO $app;

CREATE USER IF NOT EXISTS $migrate IDENTIFIED BY $(sql_string "$DB_MIGRATE_PASSWORD");
ALTER USER $migrate IDENTIFIED BY $(sql_string "$DB_MIGRATE_PASSWORD");
REVOKE ALL PRIVILEGES, GRANT OPTION FROM $migrate;
GRANT CREATE, ALTER, DROP, INDEX, REFERENCES ON \`$DB_DATABASE\`.* TO $migrate;
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB_DATABASE\`.\`migrations\` TO $migrate;
SQL

if [ -n "$DB_TEST_DATABASE" ]; then
    cat <<SQL

CREATE DATABASE IF NOT EXISTS \`$DB_TEST_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`$DB_TEST_DATABASE\`.* TO $app;
SQL
fi
