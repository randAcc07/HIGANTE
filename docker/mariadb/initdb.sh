#!/bin/sh
# Runs once, when the MariaDB volume is first created
# (/docker-entrypoint-initdb.d). Re-run provisioning by hand with:
#   docker compose exec mariadb sh /docker-entrypoint-initdb.d/initdb.sh
set -eu

sh /provisioning/provision.sh | mariadb --protocol=socket -uroot -p"$MARIADB_ROOT_PASSWORD"
