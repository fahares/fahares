#!/usr/bin/env bash
# Fahares production backup: MariaDB dump -> Telegram (age-encrypted chunks) + Google Drive (restic).
#
# Usage: fahares-backup.sh <db|media|config|verify|status|tg-chats|tg-test>
#   db      dump the database, upload to Telegram and restic, rotate local copies
#   media   back up storage/app/public (catalog page images) to restic, then prune the repo
#   config  back up app/DB container environments (secrets) to Telegram and restic
#   verify  restic integrity check + stream the latest DB snapshot and confirm it is complete
#   status  print the last result of each job; exits 1 if the last DB backup is stale or failed
#   tg-chats  list chats the bot has seen (post something in the channel first) to find TG_CHAT_ID
#   tg-test   send a test message to TG_CHAT_ID
#
# Configuration is read from $FAHARES_BACKUP_CONFIG (default /etc/fahares-backup/backup.env).
# See backup.env.example next to this script.
set -Eeuo pipefail
umask 077

CONFIG="${FAHARES_BACKUP_CONFIG:-/etc/fahares-backup/backup.env}"
[ -r "$CONFIG" ] || { echo "Config not readable: $CONFIG" >&2; exit 2; }
# shellcheck source=/dev/null
source "$CONFIG"

DB_NAME="${DB_NAME:-default}"
DB_CONTAINER_LABELS="${DB_CONTAINER_LABELS:-coolify.projectName=fahares coolify.type=database}"
APP_CONTAINER_LABELS="${APP_CONTAINER_LABELS:-coolify.projectName=fahares coolify.type=application}"
DB_IGNORE_DATA="${DB_IGNORE_DATA:-cache cache_locks sessions jobs job_batches failed_jobs}"
KEY_TABLES="${KEY_TABLES:-users field_suggestions scholarly_annotations entity_redirects manuscripts works people}"
WORK_DIR="${WORK_DIR:-/var/backups/fahares}"
STATE_DIR="${STATE_DIR:-/var/lib/fahares-backup}"
LOCAL_KEEP="${LOCAL_KEEP:-3}"
ZSTD_LEVEL="${ZSTD_LEVEL:-10}"
CHUNK_SIZE="${CHUNK_SIZE:-19M}"
MEDIA_DIR="${MEDIA_DIR:-$HOME/fahares_storage}"
KEEP_DAILY="${KEEP_DAILY:-14}"
KEEP_WEEKLY="${KEEP_WEEKLY:-8}"
KEEP_MONTHLY="${KEEP_MONTHLY:-12}"
MAX_AGE_HOURS="${MAX_AGE_HOURS:-30}"
VERIFY_READ_SUBSET="${VERIFY_READ_SUBSET:-5%}"
TG_BOT_TOKEN="${TG_BOT_TOKEN:-}"
TG_CHAT_ID="${TG_CHAT_ID:-}"
TG_API_BASE="${TG_API_BASE:-https://api.telegram.org}"
AGE_RECIPIENT="${AGE_RECIPIENT:-}"
RESTIC_REPOSITORY="${RESTIC_REPOSITORY:-}"
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE="${RESTIC_PASSWORD_FILE:-}"

STATE_FILE="$STATE_DIR/last.json"
HOST="$(hostname -s)"
JOB="${1:-}"
STEP="init"

log() { printf '[%s] %s: %s\n' "$(date '+%F %T')" "$JOB" "$*" >&2; }
die() { log "ERROR: $*"; exit 1; }
nice_io() { nice -n 15 ionice -c3 "$@"; }

tg_enabled() { [ -n "$TG_BOT_TOKEN" ] && [ -n "$TG_CHAT_ID" ]; }
restic_enabled() { [ -n "$RESTIC_REPOSITORY" ]; }

# Telegram Bot API call with retries; prints the JSON response, fails unless "ok": true.
tg_api() {
    local method="$1"; shift
    local attempt resp
    for attempt in 1 2 3 4; do
        if resp="$(curl -sS --max-time 300 "${TG_API_BASE}/bot${TG_BOT_TOKEN}/${method}" "$@")" \
            && [ "$(jq -r '.ok' <<<"$resp")" = "true" ]; then
            printf '%s' "$resp"
            return 0
        fi
        # Respect flood-control hints, otherwise back off.
        local wait; wait="$(jq -r '.parameters.retry_after // empty' <<<"${resp:-null}" 2>/dev/null || true)"
        sleep "${wait:-$((attempt * 5))}"
    done
    log "Telegram $method failed: $(jq -c '{error_code,description}' <<<"${resp:-null}" 2>/dev/null || echo "${resp:-no response}")"
    return 1
}

notify() {
    tg_enabled || { log "notify (telegram disabled): $1"; return 0; }
    tg_api sendMessage --data-urlencode "chat_id=${TG_CHAT_ID}" --data-urlencode "text=$1" >/dev/null || true
}

# Merge a job result into last.json atomically (readable by the Hermes user).
record() {
    local status="$1" detail="${2:-}" extra="${3:-{\}}"
    mkdir -p "$STATE_DIR"
    local current='{}' tmp
    [ -s "$STATE_FILE" ] && current="$(cat "$STATE_FILE")"
    tmp="$(mktemp "$STATE_DIR/.last.XXXXXX")"
    jq --arg job "$JOB" --arg status "$status" --arg detail "$detail" --arg step "$STEP" \
       --arg at "$(date -Iseconds)" --argjson extra "$extra" \
       '.[$job] = ({status: $status, finished_at: $at, step: $step, detail: $detail} + $extra)' \
       <<<"$current" >"$tmp"
    chmod 644 "$tmp"
    mv "$tmp" "$STATE_FILE"
}

# Runs on every exit once a job holds the lock; a non-zero code means the job failed.
on_exit() {
    local code=$?
    trap - EXIT
    [ "$code" -eq 0 ] && return 0
    # Never leave half-written encrypted copies or chunks behind.
    rm -rf "$WORK_DIR"/*.age "$WORK_DIR"/*.chunks "$WORK_DIR"/fahares-config-*/ 2>/dev/null || true
    record failed "exit $code at step '$STEP'" || true
    notify "❌ Fahares backup FAILED on ${HOST}
job: ${JOB}
step: ${STEP}
exit code: ${code}
see: journal / ${WORK_DIR}/backup.log"
    exit "$code"
}

find_container() {
    local filters=() label ids
    for label in $1; do filters+=(--filter "label=$label"); done
    ids="$(docker ps -q "${filters[@]}")"
    [ "$(wc -w <<<"$ids")" -eq 1 ] || die "expected exactly one running container with labels '$1', found: ${ids:-none}"
    echo "$ids"
}

db_exec() { docker exec -i "$DB_CONTAINER" sh -c "$1"; }

require_tools() {
    local t
    for t in "$@"; do command -v "$t" >/dev/null || die "missing tool: $t"; done
}

# Encrypt $1 with age, split into Telegram-downloadable chunks (bot getFile limit is 20 MB),
# upload them, and append chunk metadata (sha256, file_id, message_id) to the manifest $2.
tg_upload_encrypted() {
    local src="$1" manifest="$2" name; name="$(basename "$src")"
    local enc="$src.age" chunk_dir="$src.chunks"
    STEP="encrypt"
    age -r "$AGE_RECIPIENT" -o "$enc" "$src"
    rm -rf "$chunk_dir"; mkdir -p "$chunk_dir"
    split -b "$CHUNK_SIZE" -d -a 3 "$enc" "$chunk_dir/$name.age.part"
    local parts=("$chunk_dir"/*) total="${#parts[@]}" i=0 part resp chunks='[]'
    jq --arg sha "$(sha256sum "$enc" | cut -d' ' -f1)" --argjson size "$(stat -c %s "$enc")" \
       '.encrypted = {sha256: $sha, size: $size, cipher: "age"}' "$manifest" >"$manifest.tmp" && mv "$manifest.tmp" "$manifest"

    STEP="telegram-upload"
    for part in "${parts[@]}"; do
        i=$((i + 1))
        resp="$(tg_api sendDocument --form-string "chat_id=${TG_CHAT_ID}" --form-string "disable_notification=true" \
            --form-string "caption=${name} part ${i}/${total}" -F "document=@${part}")"
        chunks="$(jq --arg f "$(basename "$part")" --arg sha "$(sha256sum "$part" | cut -d' ' -f1)" \
            --argjson size "$(stat -c %s "$part")" \
            --arg fid "$(jq -r '.result.document.file_id' <<<"$resp")" \
            --argjson mid "$(jq -r '.result.message_id' <<<"$resp")" \
            '. + [{file: $f, sha256: $sha, size: $size, file_id: $fid, message_id: $mid}]' <<<"$chunks")"
    done
    jq --argjson c "$chunks" '.chunks = $c' "$manifest" >"$manifest.tmp" && mv "$manifest.tmp" "$manifest"
    rm -rf "$chunk_dir" "$enc"
}

tg_send_manifest() {
    local manifest="$1" caption="$2" resp
    resp="$(tg_api sendDocument --form-string "chat_id=${TG_CHAT_ID}" --form-string "caption=${caption}" -F "document=@${manifest}")"
    jq -r '.result.message_id' <<<"$resp"
}

rotate_local() {
    local pattern="$1"
    # shellcheck disable=SC2012
    ls -1t "$WORK_DIR"/$pattern 2>/dev/null | tail -n +"$((LOCAL_KEEP + 1))" | xargs -r rm -f --
}

job_db() {
    require_tools docker zstd jq curl sha256sum
    tg_enabled && require_tools age
    restic_enabled && require_tools restic
    STEP="locate-db"
    DB_CONTAINER="${DB_CONTAINER:-$(find_container "$DB_CONTAINER_LABELS")}"

    local stamp name dump manifest
    stamp="$(date +%Y%m%d-%H%M%S)"
    name="fahares-db-${stamp}"
    dump="$WORK_DIR/${name}.sql.zst"
    manifest="$WORK_DIR/${name}.manifest.json"

    STEP="dump"
    local ignore="" t
    for t in $DB_IGNORE_DATA; do ignore+=" --ignore-table-data=${DB_NAME}.${t}"; done
    log "dumping ${DB_NAME} from container ${DB_CONTAINER}"
    # The password is expanded inside the container and never appears on the host command line.
    nice_io docker exec "$DB_CONTAINER" sh -c "exec mariadb-dump -uroot -p\"\$MARIADB_ROOT_PASSWORD\" \
        --single-transaction --quick --routines --triggers --events --hex-blob \
        --default-character-set=utf8mb4 ${ignore} --databases ${DB_NAME}" \
        | nice_io zstd -q -"$ZSTD_LEVEL" -T2 -o "$dump"

    STEP="validate-dump"
    zstd -q -t "$dump"
    zstd -dc "$dump" | tail -n 1 | grep -q '^-- Dump completed' || die "dump is incomplete (no 'Dump completed' trailer)"
    local raw_size; raw_size="$(zstd -dc "$dump" | wc -c)"

    STEP="row-counts"
    local counts='{}' n
    for t in $KEY_TABLES; do
        if n="$(db_exec "mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" -N -B ${DB_NAME} -e 'SELECT COUNT(*) FROM \`${t}\`'" 2>/dev/null)"; then
            counts="$(jq --arg t "$t" --argjson n "$n" '.[$t] = $n' <<<"$counts")"
        fi
    done

    jq -n --arg name "$name" --arg at "$(date -Iseconds)" --arg host "$HOST" --arg db "$DB_NAME" \
        --arg file "$(basename "$dump")" --arg sha "$(sha256sum "$dump" | cut -d' ' -f1)" \
        --argjson size "$(stat -c %s "$dump")" --argjson raw "$raw_size" \
        --arg server "$(db_exec 'mariadb --version' | head -n1)" --argjson counts "$counts" \
        '{name: $name, created_at: $at, host: $host, database: $db, server: $server,
          dump: {file: $file, sha256: $sha, size: $size, decompressed: $raw, format: "sql+zstd"},
          row_counts: $counts}' >"$manifest"

    local tg_status="disabled" restic_status="disabled" manifest_msg=""
    if tg_enabled; then
        tg_upload_encrypted "$dump" "$manifest"
        STEP="telegram-manifest"
        local caption
        caption="✅ ${name}
size: $(numfmt --to=iec "$(stat -c %s "$dump")") ($(jq '.chunks | length' "$manifest") parts)
rows: $(jq -r '.row_counts | to_entries | map("\(.key)=\(.value)") | join(", ")' "$manifest")"
        manifest_msg="$(tg_send_manifest "$manifest" "$caption")"
        # Pin the first backup of each month so it is easy to find later.
        if [ "$(date +%d)" = "01" ]; then
            tg_api pinChatMessage -d "chat_id=${TG_CHAT_ID}" -d "message_id=${manifest_msg}" \
                -d "disable_notification=true" >/dev/null || true
        fi
        tg_status="ok"
    fi

    if restic_enabled; then
        STEP="restic-backup"
        # Uncompressed SQL deduplicates far better across days; restic compresses on its own.
        zstd -dc "$dump" | nice_io restic backup --quiet --stdin --stdin-filename fahares-db.sql \
            --tag db --host "$HOST"
        restic_status="ok"
    fi

    STEP="rotate"
    rotate_local 'fahares-db-*.sql.zst'
    rotate_local 'fahares-db-*.manifest.json'

    record ok "$name" "$(jq -c --arg tg "$tg_status" --arg rs "$restic_status" --arg mm "$manifest_msg" \
        '{name, size: .dump.size, sha256: .dump.sha256, row_counts, telegram: $tg, restic: $rs,
          telegram_manifest_message: $mm}' "$manifest")"
    log "done: $name (telegram=$tg_status restic=$restic_status)"
}

job_media() {
    require_tools restic
    restic_enabled || die "RESTIC_REPOSITORY is not set"
    [ -d "$MEDIA_DIR" ] || die "media dir not found: $MEDIA_DIR"
    STEP="restic-backup"
    nice_io restic backup --quiet --tag media --host "$HOST" "$MEDIA_DIR"
    STEP="restic-forget"
    nice_io restic forget --quiet --prune --host "$HOST" --group-by host,tags \
        --keep-daily "$KEEP_DAILY" --keep-weekly "$KEEP_WEEKLY" --keep-monthly "$KEEP_MONTHLY"
    record ok "$(restic snapshots --json --latest 1 --tag media | jq -r '.[0].short_id')"
    log "done"
}

job_config() {
    require_tools docker jq
    tg_enabled && require_tools age
    STEP="collect"
    DB_CONTAINER="${DB_CONTAINER:-$(find_container "$DB_CONTAINER_LABELS")}"
    local app name dir archive
    app="$(find_container "$APP_CONTAINER_LABELS")"
    name="fahares-config-$(date +%Y%m%d-%H%M%S)"
    dir="$WORK_DIR/$name"
    mkdir -p "$dir"
    docker inspect "$app" --format '{{range .Config.Env}}{{println .}}{{end}}' >"$dir/app.env"
    docker inspect "$DB_CONTAINER" --format '{{range .Config.Env}}{{println .}}{{end}}' >"$dir/db.env"
    docker inspect "$app" "$DB_CONTAINER" | jq '[.[] | {Name, Image: .Config.Image, Labels: .Config.Labels, Mounts}]' >"$dir/containers.json"
    local f
    for f in ${CONFIG_FILES:-}; do [ -r "$f" ] && cp --parents "$f" "$dir/"; done
    archive="$WORK_DIR/$name.tar.zst"
    tar -C "$WORK_DIR" -cf - "$name" | zstd -q -19 -o "$archive"
    rm -rf "$dir"

    if tg_enabled; then
        local manifest="$WORK_DIR/$name.manifest.json"
        jq -n --arg name "$name" --arg at "$(date -Iseconds)" --arg file "$(basename "$archive")" \
            --arg sha "$(sha256sum "$archive" | cut -d' ' -f1)" \
            '{name: $name, created_at: $at, dump: {file: $file, sha256: $sha, format: "tar+zstd"}}' >"$manifest"
        tg_upload_encrypted "$archive" "$manifest"
        STEP="telegram-manifest"
        tg_send_manifest "$manifest" "🔐 ${name} (encrypted configuration and secrets)" >/dev/null
        rm -f "$manifest"
    fi
    if restic_enabled; then
        STEP="restic-backup"
        restic backup --quiet --tag config --host "$HOST" "$archive"
    fi
    rm -f "$archive"
    record ok "$name"
    log "done: $name"
}

job_verify() {
    require_tools restic zstd
    restic_enabled || die "RESTIC_REPOSITORY is not set"
    STEP="restic-check"
    nice_io restic check --read-data-subset="$VERIFY_READ_SUBSET"
    STEP="restic-dump"
    local snap
    snap="$(restic snapshots --json --latest 1 --tag db --host "$HOST" | jq -r '.[0].short_id // empty')"
    [ -n "$snap" ] || die "no db snapshot in the repository"
    # Read the whole latest dump back from the remote and confirm the trailer is intact.
    nice_io restic dump "$snap" /fahares-db.sql | tail -n 1 | grep -q '^-- Dump completed' \
        || die "snapshot $snap does not contain a complete dump"
    record ok "snapshot $snap readable and complete" "$(jq -n --arg s "$snap" '{snapshot: $s}')"
    notify "🔎 Fahares backup verify OK on ${HOST}: snapshot ${snap} is readable and complete."
    log "done: $snap"
}

job_status() {
    [ -s "$STATE_FILE" ] || { echo "no backup has run yet"; exit 1; }
    jq . "$STATE_FILE"
    local status at age_h
    status="$(jq -r '.db.status // "missing"' "$STATE_FILE")"
    at="$(jq -r '.db.finished_at // empty' "$STATE_FILE")"
    [ -n "$at" ] || { echo "STALE: no db backup recorded"; exit 1; }
    age_h=$(( ($(date +%s) - $(date -d "$at" +%s)) / 3600 ))
    if [ "$status" != "ok" ] || [ "$age_h" -ge "$MAX_AGE_HOURS" ]; then
        echo "ALERT: last db backup status=$status, ${age_h}h ago (limit ${MAX_AGE_HOURS}h)"
        exit 1
    fi
    echo "OK: last db backup ${age_h}h ago"
}

job_tg_chats() {
    [ -n "$TG_BOT_TOKEN" ] || die "TG_BOT_TOKEN is not set"
    tg_api getUpdates -d 'allowed_updates=["channel_post","message","my_chat_member"]' \
        | jq -r '[.result[] | (.channel_post // .message // .my_chat_member).chat]
                 | unique_by(.id)[] | "\(.id)\t\(.type)\t\(.title // .username // "")"'
}

job_tg_test() {
    tg_enabled || die "TG_BOT_TOKEN and TG_CHAT_ID must both be set"
    tg_api sendMessage --data-urlencode "chat_id=${TG_CHAT_ID}" \
        --data-urlencode "text=🧪 Fahares backup bot test from ${HOST}" >/dev/null
    echo "test message sent to ${TG_CHAT_ID}"
}

case "$JOB" in
    db|media|config|verify) ;;
    status) job_status; exit ;;
    tg-chats) job_tg_chats; exit ;;
    tg-test) job_tg_test; exit ;;
    *) sed -n '2,14p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac

mkdir -p "$WORK_DIR" "$STATE_DIR"
chmod 700 "$WORK_DIR"
exec 9>"$STATE_DIR/.lock"
flock -n 9 || die "another backup job is running"
trap on_exit EXIT
"job_$JOB"
