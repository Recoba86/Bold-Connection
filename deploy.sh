#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

START_COMMIT="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
BACKUP_DIR="$ROOT_DIR/.deploy_backups/$(date +%Y%m%d_%H%M%S)"
RUN_DB_MIGRATIONS="${RUN_DB_MIGRATIONS:-1}"
CLEAN_CACHE="${CLEAN_CACHE:-1}"
RESTART_SERVICES="${RESTART_SERVICES:-}"

if [[ -t 1 ]]; then
    RED=$'\033[0;31m'
    YELLOW=$'\033[1;33m'
    BLUE=$'\033[0;34m'
    NC=$'\033[0m'
else
    RED=''
    YELLOW=''
    BLUE=''
    NC=''
fi

log() {
    printf '%s[deploy]%s %s\n' "$BLUE" "$NC" "$*"
}

warn() {
    printf '%s[deploy:warning]%s %s\n' "$YELLOW" "$NC" "$*"
}

fail() {
    printf '%s[deploy:failed]%s %s\n' "$RED" "$NC" "$*" >&2
    printf 'Rollback command: cd %q && git reset --hard %s\n' "$ROOT_DIR" "$START_COMMIT" >&2
    exit 1
}

on_error() {
    local line="$1"
    fail "Deployment stopped at line ${line}."
}

trap 'on_error $LINENO' ERR

backup_config_files() {
    local files=(
        ".env"
        ".env.local"
        ".env.production"
        "config.php"
        "config.local.php"
        "panel/inc/config.php"
    )

    mkdir -p "$BACKUP_DIR"

    local backed_up=0
    for file in "${files[@]}"; do
        if [[ -f "$ROOT_DIR/$file" ]]; then
            mkdir -p "$BACKUP_DIR/$(dirname "$file")"
            cp -p "$ROOT_DIR/$file" "$BACKUP_DIR/$file"
            backed_up=1
        fi
    done

    if [[ "$backed_up" -eq 1 ]]; then
        log "Backed up local config files to $BACKUP_DIR"
    else
        rmdir "$BACKUP_DIR" 2>/dev/null || true
        warn "No local config files were found to back up."
    fi
}

restore_config_files() {
    if [[ ! -d "$BACKUP_DIR" ]]; then
        return
    fi

    while IFS= read -r -d '' file; do
        local relative="${file#"$BACKUP_DIR"/}"
        mkdir -p "$ROOT_DIR/$(dirname "$relative")"
        cp -p "$file" "$ROOT_DIR/$relative"
    done < <(find "$BACKUP_DIR" -type f -print0)

    log "Restored local config files from backup."
}

run_php_lint() {
    log "Running PHP syntax validation."
    local lint_raw
    local lint_output
    local lint_status

    set +e
    lint_raw="$(find . -path './vendor' -prune -o -name '*.php' -exec php -l {} \; 2>&1)"
    lint_status=$?
    set -e

    lint_output="$(
        printf '%s\n' "$lint_raw" |
            grep -v 'No syntax errors detected' || true
    )"

    if [[ -n "$lint_output" ]]; then
        printf '%s\n' "$lint_output"
        fail "PHP syntax validation failed."
    fi

    if [[ "$lint_status" -ne 0 ]]; then
        printf '%s\n' "$lint_raw"
        fail "PHP syntax validation command failed."
    fi

    log "PHP syntax validation passed."
}

cleanup_cache() {
    if [[ "$CLEAN_CACHE" != "1" ]]; then
        log "Cache cleanup skipped because CLEAN_CACHE=$CLEAN_CACHE."
        return
    fi

    log "Cleaning runtime cache files when present."
    rm -f "$ROOT_DIR/api/cache_keyboard.json"
    if [[ -d "$ROOT_DIR/storage/cache" ]]; then
        find "$ROOT_DIR/storage/cache" -type f -name '*.json' -delete
    fi
}

restart_services() {
    if [[ -z "$RESTART_SERVICES" ]]; then
        log "No services configured for restart. Set RESTART_SERVICES=\"service1 service2\" if needed."
        return
    fi

    for service in $RESTART_SERVICES; do
        log "Restarting service: $service"
        sudo systemctl restart "$service"
    done
}

log "Starting deployment from $ROOT_DIR"
log "Current commit: $START_COMMIT"

command -v git >/dev/null 2>&1 || fail "git is not installed or not available in PATH."
command -v php >/dev/null 2>&1 || fail "php is not installed or not available in PATH."

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    fail "This script must run inside a git working tree."
fi

if [[ -n "$(git diff --name-only --diff-filter=U)" ]]; then
    fail "Unresolved merge conflicts are present."
fi

backup_config_files

log "Pulling latest code from origin/main."
git pull --ff-only origin main

restore_config_files

if [[ "$RUN_DB_MIGRATIONS" == "1" ]]; then
    log "Running database migration bootstrap via table.php."
    php table.php
else
    log "Database migration bootstrap skipped because RUN_DB_MIGRATIONS=$RUN_DB_MIGRATIONS."
fi

run_php_lint
cleanup_cache
restart_services

END_COMMIT="$(git rev-parse --short HEAD)"
log "Deployment completed successfully."
log "Previous commit: $START_COMMIT"
log "Deployed commit: $END_COMMIT"
printf 'Rollback command: cd %q && git reset --hard %s\n' "$ROOT_DIR" "$START_COMMIT"
