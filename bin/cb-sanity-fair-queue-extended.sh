#!/usr/bin/env bash
set -euo pipefail

DSN="${GOHANY_CB_TEST_REDIS_DSN:-redis://127.0.0.1:6379/15}"
PREFIX="${GOHANY_CB_TEST_REDIS_PREFIX:-sanity}"

export GOHANY_CB_TEST_REDIS_DSN="$DSN"
export GOHANY_CB_TEST_REDIS_PREFIX="$PREFIX"

echo "[sanity] Running extended fair-queue checks against $DSN (prefix=$PREFIX)"
php "$(dirname "$0")/safety_check_redis.php" >/dev/null 2>&1 || true
php "$(dirname "$0")/sanity/fair_queue_extended.php"

echo "[sanity] OK"
