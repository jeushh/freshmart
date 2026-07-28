#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
(cd "$ROOT/apps/api" && php artisan serve --host=127.0.0.1 --port=8000) & API_PID=$!
(cd "$ROOT/apps/web" && npm run dev -- --host 127.0.0.1) & WEB_PID=$!
trap 'kill $API_PID $WEB_PID 2>/dev/null || true' EXIT
wait
