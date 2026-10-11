#!/usr/bin/env bash
# Restore a Fahares database backup into an explicitly named MariaDB container/database.
#
# Sources (pick one):
#   --file PATH              a local .sql.zst dump, or its .age-encrypted form
#   --telegram MANIFEST      a *.manifest.json from the Telegram channel; chunks are fetched
#                            with the bot token in $TG_BOT_TOKEN and verified against sha256
#   --restic [SNAPSHOT]      the restic repository in $RESTIC_REPOSITORY (default: latest db snapshot)
#
# Target (required):
#   --container NAME         running MariaDB container to load into
#   --database NAME          database name to create/replace inside that container
#   --replace                allow dropping an existing non-empty database
#
# Other options:
#   --identity FILE          age private key (needed for .age files and --telegram)
#   --keep                   keep the downloaded/decrypted files in the work dir
#   --check-only             fetch, verify and decrypt, but do not load anything
#
# The dump is loaded with the container's root password ($MARIADB_ROOT_PASSWORD inside it).
# After loading, point the app at the database and run:  php artisan scout:import ...
set -Eeuo pipefail
umask 077

SRC_KIND="" SRC="" CONTAINER="" DATABASE="" IDENTITY="" REPLACE=0 KEEP=0 CHECK_ONLY=0

usage() { sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
log() { printf '[%s] restore: %s\n' "$(date '+%F %T')" "$*" >&2; }
die() { log "ERROR: $*"; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --file) SRC_KIND=file; SRC="$2"; shift 2 ;;
        --telegram) SRC_KIND=telegram; SRC="$2"; shift 2 ;;
        --restic) SRC_KIND=restic
            if [ $# -gt 1 ] && [ "${2#--}" = "$2" ]; then SRC="$2"; shift; fi; shift ;;
        --container) CONTAINER="$2"; shift 2 ;;
        --database) DATABASE="$2"; shift 2 ;;
        --identity) IDENTITY="$2"; shift 2 ;;
        --replace) REPLACE=1; shift ;;
        --keep) KEEP=1; shift ;;
        --check-only) CHECK_ONLY=1; shift ;;
        -h|--help) usage ;;
        *) die "unknown option: $1" ;;
    esac
done
[ -n "$SRC_KIND" ] || usage
if [ "$CHECK_ONLY" -eq 0 ]; then
    [ -n "$CONTAINER" ] && [ -n "$DATABASE" ] || die "--container and --database are required (or use --check-only)"
    [[ "$DATABASE" =~ ^[A-Za-z0-9_]+$ ]] || die "invalid database name: $DATABASE"
fi

WORK="$(mktemp -d "${TMPDIR:-/tmp}/fahares-restore.XXXXXX")"
cleanup() { [ "$KEEP" -eq 1 ] && log "files kept in $WORK" || rm -rf "$WORK"; }
trap cleanup EXIT

check_sha() { [ "$(sha256sum "$1" | cut -d' ' -f1)" = "$2" ] || die "sha256 mismatch: $1"; }

decrypt() {
    [ -n "$IDENTITY" ] || die "--identity is required to decrypt $1"
    age -d -i "$IDENTITY" -o "$2" "$1"
}

DUMP=""   # path to a .sql.zst file, or "-" when streaming from restic
case "$SRC_KIND" in
    file)
        [ -r "$SRC" ] || die "cannot read $SRC"
        if [[ "$SRC" == *.age ]]; then
            DUMP="$WORK/$(basename "${SRC%.age}")"
            decrypt "$SRC" "$DUMP"
        else
            DUMP="$SRC"
        fi
        ;;
    telegram)
        [ -n "${TG_BOT_TOKEN:-}" ] || die "TG_BOT_TOKEN is not set"
        [ -r "$SRC" ] || die "cannot read manifest $SRC"
        base="${TG_API_BASE:-https://api.telegram.org}"
        api="$base/bot${TG_BOT_TOKEN}"
        enc="$WORK/$(jq -r '.dump.file' "$SRC").age"
        : >"$enc"
        jq -c '.chunks[]' "$SRC" | while read -r chunk; do
            file="$(jq -r '.file' <<<"$chunk")"
            log "downloading $file"
            path="$(curl -sS --max-time 120 "$api/getFile" --data-urlencode "file_id=$(jq -r '.file_id' <<<"$chunk")" \
                | jq -er '.result.file_path')" || die "getFile failed for $file"
            curl -sS --fail --max-time 600 -o "$WORK/$file" "$base/file/bot${TG_BOT_TOKEN}/${path}"
            check_sha "$WORK/$file" "$(jq -r '.sha256' <<<"$chunk")"
            cat "$WORK/$file" >>"$enc"
            rm -f "$WORK/$file"
        done
        check_sha "$enc" "$(jq -r '.encrypted.sha256' "$SRC")"
        DUMP="${enc%.age}"
        decrypt "$enc" "$DUMP"
        rm -f "$enc"
        ;;
    restic)
        [ -n "${RESTIC_REPOSITORY:-}" ] || die "RESTIC_REPOSITORY is not set"
        SRC="${SRC:-$(restic snapshots --json --latest 1 --tag db | jq -r 'max_by(.time) | .short_id // empty')}"
        [ -n "$SRC" ] || die "no db snapshot found"
        log "using restic snapshot $SRC"
        DUMP="$WORK/fahares-db-restic-$SRC.sql.zst"
        restic dump "$SRC" /fahares-db.sql | zstd -q -3 -T0 -o "$DUMP"
        ;;
esac

# Telegram/file manifests carry the plain-dump checksum; verify when we have one.
if [ "$SRC_KIND" = telegram ]; then
    check_sha "$DUMP" "$(jq -r '.dump.sha256' "$SRC")"
fi
zstd -q -t "$DUMP"
zstd -dc "$DUMP" | tail -n 1 | grep -q '^-- Dump completed' || die "dump is incomplete"
log "dump verified: $DUMP ($(numfmt --to=iec "$(stat -c %s "$DUMP")"))"
[ "$CHECK_ONLY" -eq 1 ] && { KEEP=1; exit 0; }

sql() { docker exec -i "$CONTAINER" sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$@"' sh "$@"; }

existing="$(sql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DATABASE}'")"
if [ "$existing" -gt 0 ] && [ "$REPLACE" -ne 1 ]; then
    die "database '$DATABASE' in $CONTAINER already has $existing tables; pass --replace to overwrite"
fi
sql -e "DROP DATABASE IF EXISTS \`${DATABASE}\`; CREATE DATABASE \`${DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# The dump was taken with --databases <source>; rewrite those statements to target the chosen name.
log "loading into $CONTAINER/$DATABASE"
zstd -dc "$DUMP" \
    | sed -E '1,/^USE /{s/^(CREATE DATABASE[^`]*)`[^`]+`/\1`'"$DATABASE"'`/; s/^USE `[^`]+`/USE `'"$DATABASE"'`/}' \
    | sql

log "row counts in $DATABASE:"
for t in users field_suggestions scholarly_annotations entity_redirects manuscripts works people; do
    n="$(sql -N -B "$DATABASE" -e "SELECT COUNT(*) FROM \`$t\`" 2>/dev/null)" && printf '  %-24s %s\n' "$t" "$n" >&2
done
log "done. Next: point the app at '$DATABASE', then rebuild search with php artisan scout:import"
