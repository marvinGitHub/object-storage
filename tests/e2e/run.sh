#!/bin/sh
# End-to-end tests of the viewer with Playwright in Docker, from the root of the project:
#
#     sh tests/e2e/run.sh [arguments of "playwright test"]
#
# A PHP container serves a copy of the project (bind mounts are slow, and SQLite in WAL mode hangs on bind mounts of
# Windows and macOS folders) with a seeded storage; a Playwright container runs the tests against it.
# Needs the image object-storage-php85-intl (PHP 8.5 with intl, see docs) and mcr.microsoft.com/playwright.
set -e

PHP_IMAGE=${PHP_IMAGE:-object-storage-php85-intl}
PLAYWRIGHT_IMAGE=${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v1.49.1-noble}
NETWORK=object-storage-e2e
SERVER=object-storage-e2e-viewer
ROOT=$(pwd -W 2>/dev/null || pwd)

cleanup() {
    docker rm -f "$SERVER" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup
docker network create "$NETWORK" >/dev/null

MSYS_NO_PATHCONV=1 docker run -d --name "$SERVER" --network "$NETWORK" -v "$ROOT:/app:ro" "$PHP_IMAGE" sh -c '
    mkdir -p /work && tar -C /app --exclude=./appData --exclude=./.git --exclude=./tests/e2e/node_modules -cf - . | tar -C /work -xf - &&
    mkdir -p /work/appData && php /work/tests/e2e/seed.php /tmp/e2e-storage &&
    exec php -S 0.0.0.0:8080 -t /work' >/dev/null

# until the server answers
for i in $(seq 1 60); do
    if docker exec "$SERVER" php -r 'exit(@file_get_contents("http://127.0.0.1:8080/view.php") === false ? 1 : 0);' 2>/dev/null; then
        break
    fi
    sleep 1
done

MSYS_NO_PATHCONV=1 docker run --rm --network "$NETWORK" -v "$ROOT:/app" -w /app/tests/e2e \
    -e E2E_BASE_URL="http://$SERVER:8080" -e E2E_STORAGE=/tmp/e2e-storage \
    "$PLAYWRIGHT_IMAGE" sh -c "npm ci --no-audit --no-fund --silent && npx playwright test $*"
