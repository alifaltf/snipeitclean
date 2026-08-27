# ERSDev Production Deployment

This document describes how to deploy this customized Snipe-IT
repository (ERS Hardware/Software asset grouping + the Google Sheets
connector in `integrations/google-sheets-sync`) to the ERSDev server
using Docker Compose, and how to keep it updated safely afterward.

It assumes the reader is comfortable with Docker Compose and basic
Linux server administration, but does not assume familiarity with this
repository's specific customizations.

---

## 1. Prerequisites

On the ERSDev server, before starting:

- Docker Engine and the Docker Compose plugin installed (`docker compose
  version` should work).
- Git installed, with access to the private ERSDev GitHub repository
  (an SSH deploy key or an HTTPS credential with read access).
- A MySQL/MariaDB-compatible volume path with enough disk space for the
  asset management database and for growth over time.
- Enough disk space under the Docker volumes directory for the
  `storage` volume (uploaded files, backups, logs) to grow.
- Outbound network access for the `app` container to reach your mail
  server (see Mail configuration below), and for the host to pull the
  pinned `snipe/snipe-it` image from Docker Hub the first time.
- A reverse proxy (see [Section 9](#9-https-via-the-company-reverse-proxy))
  already available or planned, since this stack itself only serves
  plain HTTP on the container's mapped port.
- Access to store one production secrets file (`.env`) outside of git —
  a location on the server itself, or a secrets manager your team
  already uses. This document assumes a plain file on the server,
  permissioned to the deploying user only.

This repository does **not** build a custom image for production.
`docker-compose.production.yml` pins the stock, upstream
`snipe/snipe-it` image at an exact tested digest and layers the ERS
customization on top of it as read-only bind mounts — the same pattern
already proven locally by `docker-compose.local.yml`. There is nothing
to `docker build` here.

---

## 2. Cloning the repository

Clone the private ERSDev repository onto the server with whatever
credential your team uses for it (SSH deploy key shown here):

```bash
git clone git@github.com:<your-org>/ersdev-snipeit.git /opt/ersdev/snipeit
cd /opt/ersdev/snipeit
```

Replace the URL with the private ERSDev repository's actual clone URL.
Do not clone it into a location that's world-readable — it will shortly
contain a `.env` file with real credentials sitting alongside it (never
inside git, see below).

---

## 3. Checking out the `develop` branch

```bash
git fetch origin
git checkout develop
git pull origin develop
git status   # confirm a clean working tree before proceeding
```

Deploy from `develop` (or whichever branch/tag your team has designated
as the deployable one) — never deploy an uncommitted working tree.

---

## 4. Creating the server `.env` (never committed)

`docker-compose.yml` already declares `env_file: .env` for the `app`
service, and `.gitignore` already excludes `.env` and `.env.testing`
from git — so a real, filled-in `.env` living at the repository root on
the server is never at risk of being committed, but you must still be
careful never to `git add -f` it.

Create it once, on the server only:

```bash
cp .env.example .env
chmod 600 .env
```

Then edit `.env` and fill in real production values. `.env.example` and
`.env.docker` in this repository are **templates only** — every value
in them (including the ones that look like real passwords, e.g. in
`.env.docker`) is a placeholder shipped with the upstream project, not
a credential you should ever actually use. Generate fresh, unique
values for ERSDev.

### 4.1 Required production variables

At minimum, set these before starting the stack:

| Variable | Purpose |
| --- | --- |
| `APP_URL` | The **server-accessible** URL users and integrations will reach Snipe-IT at (e.g. `https://ersdev.example.com`), not `http://localhost:8000`. This must match what's configured at your reverse proxy — see [Section 9](#9-https-via-the-company-reverse-proxy). |
| `APP_KEY` | A unique, randomly generated Laravel application key. Generate one per environment; never reuse the value from `.env.example`/`.env.docker`, and never reuse ERSDev's key anywhere else. See 4.2 below for how to generate it. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database credentials. With this stack's own `db` service, set `DB_HOST=db` (the Compose service name) and `DB_PORT=3306`, and pick a strong, unique `DB_PASSWORD`. |
| `MYSQL_ROOT_PASSWORD` | Root password for the `db` container's first-run initialization (referenced directly by `docker-compose.yml`). Strong and unique; you should not need it day-to-day once the database exists. |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDR`, `MAIL_FROM_NAME` | Outbound mail configuration — required for password resets, checkout/checkin notifications, and any alerting Snipe-IT sends. |
| `APP_TIMEZONE` | The timezone Snipe-IT should use for dates/timestamps shown in the UI and stored for audit trails (e.g. your company's local timezone, not necessarily `UTC`). |

`APP_ENV=production` and `APP_DEBUG=false` should also be set for a
production deployment — leaving debug mode on in production leaks
stack traces and configuration to end users.

### 4.2 Generating `APP_KEY`

The simplest safe way to generate a fresh key is to run the app's own
key generator once against your filled-in `.env`, before first start:

```bash
docker run --rm --env-file .env -v "$(pwd)/.env:/var/www/html/.env" \
  snipe/snipe-it@sha256:18f70b714f78e416fb0cb9e5f1b079caa073665de3d9e42e2aa798cc0db5b8fd \
  php artisan key:generate --force
```

This writes a new `APP_KEY=` line directly into your server's `.env`.
Do this once, before first start, and never regenerate it afterward —
changing `APP_KEY` on an existing database makes previously-encrypted
data (e.g. some stored credentials/settings) unreadable.

---

## 5. Starting the stack

From the repository root on the server, with `.env` already in place:

```bash
docker compose -f docker-compose.yml -f docker-compose.production.yml up -d
```

This starts the `db` service first (waiting for its healthcheck, per
the base file's `depends_on: condition: service_healthy`), then the
`app` service using the pinned image digest with the ERS customization
mounted read-only on top of it.

---

## 6. Running migrations and clearing cached config

Once the containers are up:

```bash
docker compose -f docker-compose.yml -f docker-compose.production.yml exec app php artisan migrate --force
docker compose -f docker-compose.yml -f docker-compose.production.yml exec app php artisan optimize:clear
```

`--force` is required because `APP_ENV=production` otherwise refuses to
run migrations without an interactive confirmation. `optimize:clear`
clears any cached config/routes/views left over from a previous image
or a previous deploy, so the container reliably picks up the
mounted ERS customization files.

See [Section 8](#8-backup-before-migration) for the backup you should
take **before** this step on any deploy that isn't the very first one.

---

## 7. Checking container health and application access

```bash
docker compose -f docker-compose.yml -f docker-compose.production.yml ps
docker compose -f docker-compose.yml -f docker-compose.production.yml logs --tail=100 app
```

The base `docker-compose.yml` only defines a healthcheck for `db` (not
`app`), so `db`'s status column will show `healthy`/`unhealthy`; for
`app`, confirm it via its state (`running`, not `restarting`) and by
tailing its logs for startup errors.

Then confirm the application itself responds, from the server:

```bash
curl -I http://localhost:${APP_PORT:-8000}/
```

A `200`/`302` response indicates the app is serving requests. Full
functional verification is covered by the checklist in
[Section 12](#12-deployment-verification-checklist).

---

## 8. Backup-before-migration

Before running `php artisan migrate --force` on any deploy after the
first (i.e. any time you're deploying a new commit to an
already-running, already-populated ERSDev instance):

```bash
# Database dump
docker compose -f docker-compose.yml -f docker-compose.production.yml \
  exec db sh -c 'exec mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
  > /path/to/backups/snipeit-db-$(date +%Y%m%d-%H%M%S).sql

# Storage volume snapshot (uploaded files, local backups, logs)
docker run --rm -v snipeiters_storage:/data -v /path/to/backups:/backup \
  alpine tar czf /backup/snipeit-storage-$(date +%Y%m%d-%H%M%S).tar.gz -C /data .
```

Adjust the volume name (`snipeiters_storage` above, matching this
repository's directory-derived Compose project name — confirm yours
with `docker volume ls`) and the backup destination path for your
server. Store backups somewhere other than the same disk as the Docker
volumes, and never inside this git repository.

Do not proceed to `migrate --force` until this backup has completed
and you've confirmed the dump file is non-empty.

---

## 9. HTTPS via the company reverse proxy

This stack serves plain HTTP on `${APP_PORT:-8000}` (mapped from the
container's port 80). It does not terminate TLS itself. Put your
company's reverse proxy (nginx, Caddy, an internal load balancer, etc.)
in front of it:

- Point the proxy's upstream at `http://<ersdev-host>:${APP_PORT:-8000}`.
- Terminate HTTPS at the proxy using your company's existing certificate
  process.
- Forward `X-Forwarded-Proto`, `X-Forwarded-For`, and `Host` headers so
  Snipe-IT generates correct `https://` links and logs the real client
  IP.
- Set `APP_URL` in `.env` to the **public HTTPS URL** the proxy exposes
  (see [Section 4.1](#41-required-production-variables)), not to the
  internal `http://localhost:8000` address — a mismatch here causes
  broken redirects, mixed-content warnings, and incorrect links in
  outbound email.

The exact proxy configuration is company infrastructure, outside this
repository's scope — coordinate with whoever manages it.

---

## 10. Persistent volume backups

The two named volumes declared in `docker-compose.yml` — `storage` and
`db_data` — are preserved unchanged by `docker-compose.production.yml`
(see that file's comments) and are the entirety of ERSDev's persistent
state:

- `db_data` — the MariaDB data directory. This is the asset database
  itself; losing it loses all data.
- `storage` — uploaded files (asset images, licenses, backups Snipe-IT
  itself writes, logs).

Back up both on a regular schedule (not just before migrations — see
[Section 8](#8-backup-before-migration) for that specific procedure),
using the same `mysqldump` + volume-tar approach shown there, and verify
backups periodically by test-restoring them somewhere other than
ERSDev.

---

## 11. Safe update procedure

For a routine update (new commit on `develop`, or a new tested image
digest):

1. Announce/schedule a maintenance window if the update includes
   migrations or is otherwise not a no-downtime change.
2. Take a backup (Section 8).
3. `git pull origin develop` (or check out the specific tested commit).
4. If the tested image digest has changed, update it in
   `docker-compose.production.yml` and re-validate:
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.production.yml config --quiet
   ```
5. Pull the new image and recreate the `app` container:
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.production.yml pull app
   docker compose -f docker-compose.yml -f docker-compose.production.yml up -d
   ```
6. Run migrations and clear caches (Section 6).
7. Run through the verification checklist (Section 12).

Never skip step 2. Never point `docker-compose.production.yml` at
`snipe/snipe-it:latest` as a shortcut — always pin a digest you've
tested first, exactly as the current file does.

---

## 12. Deployment verification checklist

Run through this after every `up -d` (first deploy or update):

- [ ] `app` container is `running` (not `restarting`/`exited`) — `docker
      compose -f docker-compose.yml -f docker-compose.production.yml ps`
- [ ] `db` container is `healthy` — same `ps` output
- [ ] `php artisan migrate --force` completed with no errors
- [ ] Logging in with an existing admin account works
- [ ] The Hardware/Software sidebar navigation shows and links work
      (`/hardware?asset_group=hardware`, `?asset_group=software`)
- [ ] The Hardware/Software category filter buttons on the Assets index
      display and filter correctly
- [ ] `GET /api/v1/hardware` succeeds using a **restricted, scoped**
      integration personal access token (not an admin's own token) —
      confirms the API is reachable and correctly authenticated for
      integrations like the HRMS and the Google Sheets connector
- [ ] Both persistent volumes are present and non-empty — `docker volume
      ls` and `docker volume inspect` for `storage`/`db_data`
- [ ] A fresh backup (Section 8) exists and was verified

---

## 13. Rollback procedure

If an update causes a regression:

1. Stop the `app` container: `docker compose -f docker-compose.yml -f
   docker-compose.production.yml stop app`.
2. If the update included a migration, restore the database from the
   backup taken in Section 8 **before** rolling back application code
   — running an older application version against a newer schema is
   often more broken than staying on the failed version.
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.production.yml \
     exec -T db sh -c 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
     < /path/to/backups/snipeit-db-<the-pre-update-backup>.sql
   ```
3. Check out the previous known-good commit (`git checkout <previous
   commit>`), and if the image digest was changed, revert
   `docker-compose.production.yml` to the previous tested digest.
4. `docker compose -f docker-compose.yml -f docker-compose.production.yml
   up -d`.
5. Run through the verification checklist (Section 12) again.
6. Once ERSDev is confirmed stable, investigate the regression on a
   non-production copy before attempting the update again.

---

## 14. Critical warnings

**Never run `docker compose down -v`** against this stack. The `-v`
flag deletes named volumes — that means `db_data` (the entire asset
database) and `storage` (every uploaded file) are permanently
destroyed, with no confirmation prompt. If you need to stop the stack,
use `docker compose -f docker-compose.yml -f docker-compose.production.yml
stop` or `down` (without `-v`) instead — both leave the named volumes
intact.

**Never commit any of the following, under any circumstance:**

- `.env` (already `.gitignore`d — do not force-add it)
- `connector.env` or any other filled-in copy of
  `integrations/google-sheets-sync/.env.example`
- Snipe-IT personal access tokens / API tokens, anywhere (code,
  comments, commit messages, this document)
- Google service-account JSON key files, or any credential file
  `GOOGLE_APPLICATION_CREDENTIALS` might point at
- Database dumps or `storage` volume backups (these contain real
  production data — store them outside git, per Section 8/10)

If any of the above is ever accidentally staged, unstage and remove it
before committing — do not rely on a later commit to "delete" it, since
git history retains it. Rotate the credential immediately if a secret
was ever actually committed or pushed.

---

## 15. Integrations: HRMS and the Google Sheets connector

**HRMS and any other external system must call Snipe-IT's API at the
server-accessible URL** (the same `APP_URL`/reverse-proxy address
configured in Section 4.1 and 9 — e.g.
`https://ersdev.example.com/api/v1/...`), **never**
`http://localhost:8000/api/v1/...`. `localhost` only resolves to "the
machine making the request" — from any host other than the ERSDev
server itself, it does not reach Snipe-IT at all. Give integrations a
restricted, scoped API token (see the verification checklist in Section
12), not a shared admin token.

**`integrations/google-sheets-sync` is a standalone tool, not part of
this Docker Compose stack, and this deployment does not modify or
configure it.** It runs separately (via Node.js, per its own README)
and needs its own external secrets configured directly on whatever host
runs it:

- A `connector.env` file (or equivalent), based on
  `integrations/google-sheets-sync/.env.example`, containing
  `SNIPEIT_BASE_URL` (the same server-accessible URL as above, not
  `localhost`), `SNIPEIT_API_TOKEN`, `GOOGLE_SPREADSHEET_ID`, and
  `GOOGLE_SHEET_NAME`.
- A Google service-account JSON key file, referenced by
  `GOOGLE_APPLICATION_CREDENTIALS`, stored outside the repository.

Both must live outside this repository and outside git entirely — see
the warnings in Section 14. Deploying ERSDev's Snipe-IT instance does
not, by itself, deploy or configure this connector.
