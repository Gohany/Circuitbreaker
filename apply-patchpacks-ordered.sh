#!/usr/bin/env bash
set -euo pipefail

PATCH_DIR="${1:-.}"
REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"

if [[ -z "$REPO_ROOT" ]]; then
  echo "ERROR: run this inside a git repo."
  exit 1
fi

command -v unzip >/dev/null || { echo "ERROR: unzip is required"; exit 1; }
command -v rsync >/dev/null || { echo "ERROR: rsync is required"; exit 1; }

# Repo identity hints
REPO_BASENAME="$(basename "$REPO_ROOT")"
REPO_BASENAME_LC="$(printf '%s' "$REPO_BASENAME" | tr '[:upper:]' '[:lower:]')"

repo_composer_name=""
if [[ -f "$REPO_ROOT/composer.json" ]]; then
  # Prefer php if present; fallback to grep/sed
  if command -v php >/dev/null; then
    repo_composer_name="$(php -r '($j=json_decode(@file_get_contents($argv[1]),true)) && isset($j["name"]) ? print($j["name"]) : null;' "$REPO_ROOT/composer.json" 2>/dev/null || true)"
  else
    repo_composer_name="$(grep -E '"name"\s*:\s*"' "$REPO_ROOT/composer.json" | head -n1 | sed -E 's/.*"name"\s*:\s*"([^"]+)".*/\1/' || true)"
  fi
fi

# Expected repo directory names (case-insensitive)
EXPECTED_DIRS=("$REPO_BASENAME_LC")
if [[ -n "$repo_composer_name" ]]; then
  suffix="${repo_composer_name##*/}"
  EXPECTED_DIRS+=("$(printf '%s' "$suffix" | tr '[:upper:]' '[:lower:]')")
fi
# Common wrapper names we’ve seen in these patch zips
EXPECTED_DIRS+=("circuitbreaker" "circuitbreaker-symfony-bundle")

ORDER=(
  "gohany-circuitbreaker-fair-queue-bulkhead-patch.zip"
  "gohany-circuitbreaker-fair-queue-weighted-pump-patch.zip"
  "gohany-circuitbreaker-fair-queue-testing-pack.zip"
  "gohany-circuitbreaker-fair-queue-testing-pack-2.zip"
  "gohany-circuitbreaker-fair-queue-testing-pack-3.zip"
  "gohany-circuitbreaker-fair-queue-testing-pack-4.zip"
  "gohany-circuitbreaker-redis-lua-capability-autodetect-patch.zip"
  "gohany-circuitbreaker-master-patch.zip"
)

composer_name_from() {
  local composer_path="$1"
  [[ -f "$composer_path" ]] || return 1
  if command -v php >/dev/null; then
    php -r '($j=json_decode(@file_get_contents($argv[1]),true)) && isset($j["name"]) ? print($j["name"]) : null;' "$composer_path" 2>/dev/null || true
  else
    grep -E '"name"\s*:\s*"' "$composer_path" | head -n1 | sed -E 's/.*"name"\s*:\s*"([^"]+)".*/\1/' 2>/dev/null || true
  fi
}

looks_like_repo_root() {
  local d="$1"
  [[ -f "$d/composer.json" || -d "$d/src" || -d "$d/tests" || -d "$d/lib" ]]
}

pick_payload_root() {
  local tmp="$1"

  # 1) If there is a top-level dir whose name matches current repo basename (case-insensitive), prefer it.
  while IFS= read -r d; do
    local dn_lc
    dn_lc="$(basename "$d" | tr '[:upper:]' '[:lower:]')"
    for want in "${EXPECTED_DIRS[@]}"; do
      if [[ "$dn_lc" == "$want" ]] && looks_like_repo_root "$d"; then
        echo "$d"
        return 0
      fi
    done
  done < <(find "$tmp" -mindepth 1 -maxdepth 1 -type d ! -name "__MACOSX" -print)

  # 2) If repo composer name is known, find a composer.json in the zip matching it (any depth, skipping vendor/)
  if [[ -n "$repo_composer_name" ]]; then
    best=""
    best_len=999999
    while IFS= read -r cj; do
      local n
      n="$(composer_name_from "$cj")"
      if [[ "$n" == "$repo_composer_name" ]]; then
        local d
        d="$(dirname "$cj")"
        local rel="${d#"$tmp"/}"
        local len="${#rel}"
        if (( len < best_len )); then
          best="$d"
          best_len="$len"
        fi
      fi
    done < <(find "$tmp" -name composer.json -not -path '*/vendor/*' -not -path '*/__MACOSX/*' -print)

    if [[ -n "$best" ]]; then
      echo "$best"
      return 0
    fi
  fi

  # 3) If exactly one top-level folder exists, use it.
  mapfile -t top < <(find "$tmp" -mindepth 1 -maxdepth 1 ! -name "__MACOSX" -printf '%P\n')
  if [[ ${#top[@]} -eq 1 && -d "$tmp/${top[0]}" ]]; then
    echo "$tmp/${top[0]}"
    return 0
  fi

  # 4) Fallback: tmp root
  echo "$tmp"
}

apply_zip() {
  local zip="$1"
  local path="$PATCH_DIR/$zip"

  if [[ ! -f "$path" ]]; then
    echo "SKIP (missing): $zip"
    return 0
  fi

  echo "==> Applying $zip"
  local tmp; tmp="$(mktemp -d)"
  unzip -q "$path" -d "$tmp"

  local root; root="$(pick_payload_root "$tmp")"
  echo "    Payload root: ${root#"$tmp"/}"

  # Overlay merge; don't delete anything
  rsync -a --checksum \
    --exclude ".git/" \
    --exclude "__MACOSX/" \
    "$root"/ "$REPO_ROOT"/

  rm -rf "$tmp"
}

echo "Repo: $REPO_ROOT"
echo "Repo composer name: ${repo_composer_name:-"(unknown)"}"
echo "Patch dir: $PATCH_DIR"
echo

for z in "${ORDER[@]}"; do
  apply_zip "$z"
done

echo
echo "Done. Review:"
echo "  git status"
echo "  git diff"