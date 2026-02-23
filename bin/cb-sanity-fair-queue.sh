#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

REDIS_DSN="${GOHANY_CB_TEST_REDIS_DSN:-redis://127.0.0.1:6379/15}"
PREFIX="${GOHANY_CB_TEST_REDIS_PREFIX:-sanity}"

echo "==> Redis DSN: ${REDIS_DSN}"
echo "==> Prefix: ${PREFIX}"

if ! command -v php >/dev/null 2>&1; then
  echo "php not found" >&2
  exit 2
fi

php "${ROOT_DIR}/bin/sanity/fair_queue.php" \
  --dsn "${REDIS_DSN}" \
  --prefix "${PREFIX}" \
  "$@"
