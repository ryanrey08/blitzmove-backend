# BlitzMove production deployment

Live at **https://blitzmove.blitzdev.com.ph** (API: `https://blitzmove.blitzdev.com.ph/api/v1`).
Server: `134.209.100.226` (Ubuntu 24.04, shared with SofiaCart, which is not touched by any of this).
Everything BlitzMove-specific lives in `/opt/blitzmove` on the server:

| File | What it is |
|---|---|
| `docker-compose.yml` | Copied from this folder on every deploy |
| `.env` | `TAG` (deployed image tag), DB passwords, `SESSION_COOKIE_SECURE`. Mode 600, never in Git |
| `backend.env` | Laravel environment (`APP_KEY`, URLs, Mapbox, mail). Mode 600, never in Git. Template: `backend.env.example` |
| `backups/` | Database dumps: one before every deploy plus nightly at 02:30 Manila (newest 14 kept) |
| `admin-initial-password.txt` | First admin password (delete after the first login and password change) |

## Layout

```
internet :443 ─ host nginx (/etc/nginx/sites-available/blitzmove; :80 and the bare IP redirect here)
                 ├─ /api/v1/*, /up ─► 127.0.0.1:8100  web (nginx, public/ only) ─► app (PHP-FPM 8.3)
                 └─ everything else ► 127.0.0.1:3100  frontend (Next.js standalone, node server.js)
                                                     app, scheduler ─► db (MySQL 8.4, no published port)
```

* Laravel is at `/api/v1` rather than all of `/api`, because Next.js owns `/api/auth/*`, `/api/proxy/*` and
  `/api/health` on the same domain.
* The browser only talks to Next.js. Next.js calls Laravel server-side (`API_URL=http://web`), so no CORS
  is needed, and the Sanctum token stays in the httpOnly `bm_session` cookie.
* `scheduler` runs `php artisan schedule:work` (prunes expired tokens daily). Queues run `sync`; no worker.
* Containers use `restart: unless-stopped` and Docker is enabled at boot, so everything returns after a reboot.
* Container logs are capped at 5 × 10 MB per container (`docker compose logs <service>`).
* Laravel caches config, routes and events on every container start (`entrypoint.sh`).

## Deploying an update

On the Mac, with Docker Desktop running and both repos' branches pushed:

```bash
cd ~/Desktop/blitzmove/blitzmove-backend
HEALTH_URL=https://blitzmove.blitzdev.com.ph deploy/production/deploy.sh trip-scheduling-overhaul
```

It builds the three images locally, uploads them, backs up the database, runs `migrate --force`, restarts the
stack, waits for health checks, and removes images older than the previous release.

**Rollback**: set `TAG=` in `/opt/blitzmove/.env` back to the previous tag (`docker images blitzmove-app`) and run
`docker compose up -d`. If a migration must be undone, restore the matching dump from `backups/`.

## Day-to-day commands (on the server, in `/opt/blitzmove`)

```bash
docker compose ps                      # status
docker compose logs -f app frontend    # logs
docker compose restart app scheduler   # restart the API (e.g. after editing backend.env)
docker compose restart frontend
docker compose stop                    # stop BlitzMove (SofiaCart keeps running)
docker compose up -d                   # start it again
docker compose exec app php artisan <command>
./backup.sh                            # manual database backup
gunzip -c backups/<file>.sql.gz | docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" blitzmove'   # restore
```

## HTTPS and server security

* Let's Encrypt certificate for `blitzmove.blitzdev.com.ph` (webroot `/var/www/letsencrypt`), renewed by
  `certbot.timer`; `/etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh` reloads nginx after a renewal.
  Check with `certbot certificates` or `certbot renew --dry-run`.
* Secure, httpOnly session cookie (`SESSION_COOKIE_SECURE=true`, `SESSION_SECURE_COOKIE=true`) and HSTS.
* SSH is key-only (`/etc/ssh/sshd_config.d/00-hardening.conf`). Add another person's key to
  `/root/.ssh/authorized_keys` before they need access; the DigitalOcean web console still works for recovery.
* fail2ban bans an IP for 1 h after 5 failed SSH logins in 10 min (`fail2ban-client status sshd`;
  unban with `fail2ban-client set sshd unbanip <ip>`).
* ufw allows only 22, 80 and 443. Docker publishes BlitzMove's ports on 127.0.0.1 only.
