#!/bin/sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="$ROOT/docker-compose.typemill.yml"

docker compose -f "$COMPOSE_FILE" exec -T typemill sh -ec '
    set -eu
    export DEBIAN_FRONTEND=noninteractive

    if ! command -v chromium >/dev/null 2>&1; then
        apt-get update -qq
        apt-get install -y -qq chromium >/dev/null
    fi

    if ! command -v node >/dev/null 2>&1; then
        apt-get update -qq
        apt-get install -y -qq ca-certificates curl gnupg >/dev/null
        curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
        apt-get install -y -qq nodejs >/dev/null
    fi

    export PUPPETEER_EXECUTABLE_PATH="$(command -v chromium || command -v chromium-browser)"
    export PUPPETEER_SKIP_DOWNLOAD=true
    export TM_BASE_URL="${TM_BASE_URL:-http://127.0.0.1}"

    cd /var/www/html/tests/browser
    if [ ! -x "$PUPPETEER_EXECUTABLE_PATH" ]; then
        echo "Chromium executable not found" >&2
        exit 1
    fi
    if [ ! -d node_modules/puppeteer ]; then
        npm install --no-audit --no-fund
    fi

    node admin-pages.mjs
'
