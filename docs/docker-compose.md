Overview
========

This project now includes three docker-compose templates to support two workflows:

1. CI / image builds (build images and push to Docker Hub)
2. Full-stack development either by building locally or by pulling prebuilt images
3. Pull-only compose for users who want to run the stack from Docker Hub images

Overview
========

This project now provides two main workflows:

1. CI / image builds that produce multiple image variants (all-in-one, php-fpm, nginx) pushed to Docker Hub.
2. Local development / deployment using docker-compose: either a modular setup (separate php-fpm + nginx) or the legacy single-image "all-in-one" master image (note: the all-in-one image does not include nginx; nginx is run separately).

Files
-----

- `docker-compose.base.yml` — CI-oriented compose that builds multiple image variants using build targets in the `Dockerfile`. It builds and tags:
  - `sparkison/m3u-editor:${IMAGE_TAG}` (default points to the all-in-one target)
  - `sparkison/m3u-editor:allinone`
  - `sparkison/m3u-editor:php-fpm`
  - `sparkison/m3u-editor:nginx`
  - `sparkison/m3u-proxy:${IMAGE_TAG}`

- `docker-compose.full.yml` — Full-stack compose that runs `postgres`, `redis`, `m3u-proxy`, plus separate `php-fpm` and `nginx` services by default. This is the recommended modular layout for development and production-like testing.

- `docker-compose.allinone.yml` — An overlay compose file that replaces the separate `php-fpm`+`nginx` services with the single `m3u-editor` all-in-one image (backwards compatibility). Use this in combination with `docker-compose.full.yml` when you want the legacy single-image behavior.
- `docker-compose.allinone.yml` — An overlay compose file that replaces the separate `php-fpm` service with the single `m3u-editor` all-in-one image (backwards compatibility). Note: nginx is not bundled into the all-in-one image and must be run as the separate `nginx` service/image.

- `docker-compose.images.yml` — Pull-only compose for users who prefer to use prebuilt images from Docker Hub (useful when supplying your own external Postgres/Redis).

Helper scripts
--------------

- `docker/8.4/nginx/docker-entrypoint.sh` — entrypoint for the nginx image. It envsubst's the nginx templates (`nginx.tmpl` and `laravel.tmpl`) using environment variables and starts nginx. This allows runtime configuration of ports and proxy targets (e.g., FPMPORT, APP_PORT, M3U_PROXY_PORT).

Quick examples
--------------

Build and push images via GitHub Actions (recommended):

The repo includes a sample workflow at `.github/workflows/docker-build.yml` which builds and pushes multi-arch images for the targets `allinone`, `php-fpm`, and `nginx`. It also tags the main image with `IMAGE_TAG` derived from the branch (main -> latest, experimental -> experimental, other branches -> dev-<branch>).

Manually build the variants locally (CI-like):

```sh
# Build all variants (this will produce images: allinone, php-fpm, nginx, and the main tag)
IMAGE_TAG=experimental docker compose -f docker-compose.base.yml build --parallel

# Push them to Docker Hub using docker CLI or `docker compose push` (CI usually does this automatically)
docker push sparkison/m3u-editor:allinone
docker push sparkison/m3u-editor:php-fpm
docker push sparkison/m3u-editor:nginx
docker push sparkison/m3u-editor:experimental
```

Run the modular full stack (separate services):

```sh
# Pull images from Docker Hub (no local build)
IMAGE_TAG=latest docker compose -f docker-compose.full.yml up -d

# Or build locally and run
IMAGE_TAG=local docker compose -f docker-compose.full.yml up --build -d
```

Run using the legacy all-in-one image (single-image mode):

```sh
# Run full stack using the all-in-one image instead of separated services
IMAGE_TAG=dev docker compose -f docker-compose.full.yml -f docker-compose.allinone.yml up -d
```

Use external Postgres/Redis
--------------------------

If you want to use your own Postgres or Redis instances (on the host or elsewhere):

- Omit the `postgres` and/or `redis` services from the compose file, or use `docker-compose.images.yml` and remove/comment those services.
- Set environment variables so the app uses them (for macOS, `host.docker.internal` is useful):

```env
DB_HOST=host.docker.internal
DB_PORT=5432
DB_DATABASE=mydb
DB_USERNAME=myuser
DB_PASSWORD=secret
REDIS_HOST=host.docker.internal
REDIS_PORT=6379
```

Runtime-configurable ports and templates
---------------------------------------

The nginx entrypoint uses `envsubst` to render nginx config templates at container start. That means users can configure ports and proxy targets at runtime using environment variables (examples):

- `APP_PORT` — port mapped on the host that will be routed to nginx (default 36400).
- `FPMPORT` — php-fpm port (default 9000).
- `M3U_PROXY_PORT` — embedded m3u-proxy port (default 38085).
- `REVERB_PORT` — websockets port (default 36800).

When running separate `php-fpm` + `nginx` services, the `nginx` container renders `laravel.tmpl` and proxies to the php-fpm container using `${FPMPORT}`. When running the legacy `all-in-one` image, `start-container` performs php-fpm templating and starts supervisord (the all-in-one image does not include nginx — use the separate `nginx` image when you need HTTP hosting).

CI recommendations
------------------

- Use the provided GitHub Actions workflow (`.github/workflows/docker-build.yml`) to build and push the three image variants and tag the main image by branch.
- For local testing you can use `docker compose -f docker-compose.base.yml build` to build the same targets.

Next steps / optional
---------------------

- Add a small `Makefile` for local convenience (build/push/tag). I can add one if you want.
- Add healthchecks and restart policies for production readiness in `docker-compose.full.yml`.
- Optionally split the Dockerfile further or reduce runtime packages in the `runtime` stage if you want smaller php-fpm images.

