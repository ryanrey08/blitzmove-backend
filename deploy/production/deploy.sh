#!/usr/bin/env bash
# Builds BlitzMove on this machine and rolls it out to the production server.
#   deploy/production/deploy.sh [branch]        (run from blitzmove-backend; default branch: trip-scheduling-overhaul)
# Expects blitzmove-frontend next to blitzmove-backend, Docker locally, and root SSH to the server.
# The server keeps its secrets in /opt/blitzmove/.env and backend.env; this script never touches them.
set -euo pipefail

SERVER=${SERVER:-root@134.209.100.226}
BRANCH=${1:-trip-scheduling-overhaul}
HERE=$(cd "$(dirname "$0")" && pwd)
BACKEND=$(cd "$HERE/../.." && pwd)
FRONTEND=$(cd "$BACKEND/../blitzmove-frontend" && pwd)

git -C "$BACKEND" fetch -q origin "$BRANCH"
git -C "$FRONTEND" fetch -q origin "$BRANCH"
B_SHA=$(git -C "$BACKEND" rev-parse --short "origin/$BRANCH")
F_SHA=$(git -C "$FRONTEND" rev-parse --short "origin/$BRANCH")
TAG="$B_SHA-$F_SHA"
echo "Deploying $BRANCH as $TAG to $SERVER"

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir "$WORK/backend" "$WORK/frontend"
git -C "$BACKEND" archive "origin/$BRANCH" | tar -x -C "$WORK/backend"
git -C "$FRONTEND" archive "origin/$BRANCH" | tar -x -C "$WORK/frontend"

docker build -q -f "$WORK/backend/deploy/production/Dockerfile" --target app -t "blitzmove-app:$TAG" "$WORK/backend"
docker build -q -f "$WORK/backend/deploy/production/Dockerfile" --target web -t "blitzmove-web:$TAG" "$WORK/backend"
docker build -q -t "blitzmove-frontend:$TAG" "$WORK/frontend"

echo "Uploading images"
docker save "blitzmove-app:$TAG" "blitzmove-web:$TAG" "blitzmove-frontend:$TAG" | gzip -1 | ssh "$SERVER" 'gunzip | docker load -q'
scp -q "$WORK/backend/deploy/production/docker-compose.yml" "$SERVER:/opt/blitzmove/docker-compose.yml"

ssh "$SERVER" TAG="$TAG" bash -s <<'REMOTE'
set -euo pipefail
cd /opt/blitzmove
PREVIOUS=$(grep '^TAG=' .env | cut -d= -f2 || true)

# Back up the database before migrating (keeps the last 14).
if docker compose ps --status running db | grep -q db; then
  mkdir -p backups && chmod 700 backups
  docker compose exec -T db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines blitzmove' \
    | gzip > "backups/blitzmove-$(date -u +%Y%m%dT%H%M%SZ)-${PREVIOUS:-none}.sql.gz"
  ls -1t backups/*.sql.gz | tail -n +15 | xargs -r rm --
fi

sed -i "s/^TAG=.*/TAG=$TAG/" .env
docker compose up -d --wait db
docker compose run --rm --no-deps app php artisan migrate --force --no-ansi
docker compose up -d --remove-orphans --wait
docker compose ps

# Drop older BlitzMove images (keeps the one just deployed and the previous one for rollback).
for repo in blitzmove-app blitzmove-web blitzmove-frontend; do
  docker images "$repo" --format '{{.Tag}}' | grep -vx -e "$TAG" -e "${PREVIOUS:-}" | xargs -r -I{} docker rmi -f "$repo:{}" >/dev/null || true
done
REMOTE

curl -fsS -o /dev/null -w "Health check: %{http_code}\n" "${HEALTH_URL:-http://127.0.0.1:1}/up" 2>/dev/null || echo "Set HEALTH_URL=https://your-domain to check /up from here."
