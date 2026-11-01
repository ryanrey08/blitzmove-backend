#!/usr/bin/env bash
# Nightly BlitzMove database backup (installed as /opt/blitzmove/backup.sh, run by /etc/cron.d/blitzmove-backup).
# Keeps the newest 14 dumps in /opt/blitzmove/backups.
set -euo pipefail
umask 077
cd /opt/blitzmove
mkdir -p backups
docker compose exec -T db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines blitzmove' </dev/null \
  | gzip > "backups/blitzmove-$(date -u +%Y%m%dT%H%M%SZ)-nightly.sql.gz"
ls -1t backups/*.sql.gz | tail -n +15 | xargs -r rm --
