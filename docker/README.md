# Docker development on main

Requirements: Docker Engine/Desktop with Compose v2. PHP 8.4, Node 24,
and Nginx run in containers; MySQL runs on the host. No host PHP/npm installation is needed.
The existing lockfiles are installed without upgrades. Redis is not required.

## First run

Keep your existing `.env`, including DB_DATABASE, DB_USERNAME and DB_PASSWORD.
Laravel connects to the existing host MySQL database through
`host.docker.internal`; Compose overrides only the database host address.
This installation uses host database `lead_crm`. Docker Desktop resolves the
hostname automatically; connectivity was verified without changing MySQL's
loopback binding or grants. On native Linux Engine, test host connectivity
before adding `extra_hosts: ["host.docker.internal:host-gateway"]`; a loopback-only
MySQL listener is not generally reachable through that gateway.

The previous isolated MySQL service and its volume are retained under the
`isolated-db` profile. They are not the application's current database.
No data is copied between databases. Do not run migrations or seeders simply
to reveal existing data.

```sh
docker compose config --quiet
docker compose build
docker compose up -d
docker compose logs -f init node
# Wait for init to finish and Vite to report ready.
# Only if APP_KEY is missing in .env:
# docker compose exec app php artisan key:generate
docker compose exec app php artisan about
docker compose exec app php artisan route:list
```

Open http://localhost:8080. Vite uses http://localhost:5174. Both ports bind only
to the local machine. Remote access requires deliberate changes to the Docker
Vite wrapper's origin/HMR settings and Compose bindings.

No migration, seeding, database reset, or key generation runs automatically.
Start background processing only when you want queued jobs and
scheduled automation to execute:

```sh
docker compose --profile background up -d
```

## Daily commands

```sh
docker compose build
docker compose up -d
docker compose ps
docker compose --profile background stop
docker compose --profile background down
docker compose restart
docker compose logs --tail=100
docker compose logs -f app web node
docker compose exec --user www-data app composer install
docker compose exec --user www-data app php artisan about
docker compose exec node npm ci
# Vite already runs in node; to restart it:
docker compose restart node
# To run Vite interactively (avoid a second server on the same port):
docker compose stop node
docker compose run --rm --service-ports node npm run dev -- --config docker/vite.config.js
```

Validate a production asset build without interrupting the live Vite server:

```sh
docker compose exec node npm run build -- --outDir /tmp/lead-crm-build
```

To build into Docker's public/build instead, stop Vite first:

```sh
docker compose stop node
docker compose run --rm node sh -c 'rm -f public/hot && npm run build'
```

This removes only the Docker volume's development-server marker. Restart node
to return to HMR. Existing host public/hot and public/build are never used.

## Volume and dependency behavior

Source is bind-mounted. Dedicated named volumes cover vendor, node_modules,
public, storage and bootstrap/cache. Host dependencies/generated assets cannot
mask container dependencies, and container builds do not change host public/.
The init service installs composer.lock on each fresh Compose startup; node
runs npm ci before Vite. Recreate init/node after changing branches or lockfiles:

```sh
docker compose up -d --force-recreate init app node
```

Public source files are copied from the image into the public volume by init;
rebuild/recreate init after editing tracked public files. PHP writes as www-data;
init assigns ownership only inside Docker volumes, without chmod 777. Run manual
Artisan/Composer commands as www-data to preserve that ownership.

The host database is independent of Compose. Docker uploads and the retained
isolated database volume survive stop/down/up. Do not use `down -v`.
The project name `lead-crm-dev` isolates this setup from older Sail containers.
The worker and scheduler are opt-in because they can send integration requests.
Existing .env integration credentials still apply; use local test credentials.

## Validation performed

Validated on main: Compose configuration/build/start, Composer platform
requirements, normal migrations and migration status, Artisan about, 74
application routes, schedule listing, Vite startup, and production asset build.
The Docker build and a separate clean-main build produced the same CSS hash.
At 1440x1000, login screenshots from clean-main production assets and Docker
HMR were pixel-identical. Authenticated pages were not visually compared.
Tracked dependency/frontend files and host .env/public assets remained unchanged.
Vite's existing config emits deprecation warnings; no dependency upgrades were made.

## Existing database connection verification

Switched only the Laravel app to host MySQL lead_crm after read-only checks
confirmed existing CRM records. No migrations, seeds, imports or database/volume
deletions were performed during this reconnection. Use
`docker compose exec --user www-data app php artisan optimize:clear --except=cache`
to clear bootstrap files without deleting database-backed cache entries.
