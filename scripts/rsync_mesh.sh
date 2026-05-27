#!/bin/bash
# rsync_mesh.sh — Bidirectional mesh sync: push local data to every peer listed
# in the RSYNC_PEERS environment variable.
#
# Called by rsync_daemon.sh every 30 seconds on each node.
# Uses a flock guard so a slow run never overlaps the next tick.
#
# Environment variable required:
#   RSYNC_PEERS  — space-separated list of peer hostnames/IPs
#                  e.g. "mirror-1 mirror-2"  (set in docker-compose.yml)
#
# SSH key expected at /var/www/.ssh/id_archive (copied by entrypoint scripts).

set -euo pipefail

# ── Self-exclusion lock ───────────────────────────────────────────────────────
# If a previous run is still in progress when the daemon fires again, bail out
# immediately rather than letting two syncs race on the same DB snapshot.
LOCK_FILE="/tmp/rsync_mesh.lock"
exec 200>"${LOCK_FILE}"
if ! flock -n 200; then
    # Not an error — just a busy signal; daemon logs it separately.
    exit 2
fi

# ── Configuration ─────────────────────────────────────────────────────────────
PEERS="${RSYNC_PEERS:-}"
REMOTE_USER="root"
DATA_DIR="/var/www/shimmie/data"
LOCAL_DB="${DATA_DIR}/shimmie.sqlite"
SSH_KEY="/var/www/.ssh/id_archive"
AUDIT_LOG="/var/log/rsync_audit.log"
SOURCE_HOST="$(hostname)"
SSH_OPTS="-i ${SSH_KEY} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=10 -o BatchMode=yes"

ts() { TZ=Asia/Kuala_Lumpur date '+%Y-%m-%d %H:%M:%S'; }

log() { echo "[$(ts)] [$1] rsync_mesh(${SOURCE_HOST}): ${2}" >> "${AUDIT_LOG}"; }

# ── Bail early if there are no peers ─────────────────────────────────────────
if [ -z "${PEERS}" ]; then
    log "WARN" "RSYNC_PEERS is empty — nothing to sync."
    exit 0
fi

# ── SSH key check ─────────────────────────────────────────────────────────────
if [ ! -f "${SSH_KEY}" ]; then
    log "ERROR" "SSH key not found at ${SSH_KEY} — skipping sync."
    exit 1
fi

# ── SQLite consistent snapshot ────────────────────────────────────────────────
# sqlite3 .backup acquires a shared lock and writes a guaranteed-consistent
# copy even under concurrent Apache writes — avoids transferring a half-written
# WAL file.
BACKUP_DB="/tmp/rsync_db_snapshot_$$.sqlite"
BACKUP_MADE=0
if [ -f "${LOCAL_DB}" ]; then
    if sqlite3 "${LOCAL_DB}" ".backup ${BACKUP_DB}" 2>/dev/null; then
        BACKUP_MADE=1
    else
        log "WARN" "sqlite3 backup failed — DB will not be synced this round."
    fi
fi

# ── Sync to each peer ─────────────────────────────────────────────────────────
SYNC_ERRORS=0

for PEER in ${PEERS}; do
    # Strict hostname validation — alphanumeric, hyphens, dots only.
    if echo "${PEER}" | grep -qP '[^a-zA-Z0-9.\-]'; then
        log "ERROR" "Invalid peer hostname '${PEER}' — skipping."
        continue
    fi

    log "INFO" "→ ${PEER}: starting"

    # ── Media files (live DB excluded to avoid partial reads) ─────────────────
    # --update  : never overwrite a file that is newer on the receiver
    #             (prevents a lagging mirror from clobbering freshly-written data)
    # --delete  : remove files that no longer exist on this node
    #             (keeps all three nodes consistent after a post is deleted)
    # --checksum: compare file contents, not just mtime/size
    RSYNC_FILE_OUT=$(rsync \
        --archive \
        --compress \
        --delete \
        --checksum \
        --update \
        --partial \
        --timeout=60 \
        --exclude='shimmie.sqlite' \
        --exclude='shimmie.sqlite-journal' \
        --exclude='shimmie.sqlite-shm' \
        --exclude='shimmie.sqlite-wal' \
        --rsh="ssh ${SSH_OPTS}" \
        "${DATA_DIR}/" \
        "${REMOTE_USER}@${PEER}:${DATA_DIR}/" 2>&1) || FILE_EXIT=$?

    FILE_EXIT="${FILE_EXIT:-0}"

    # rsync exit 24 = "some files vanished before they could be transferred"
    # This is non-fatal — happens during container startup or concurrent writes.
    if [ "${FILE_EXIT}" -eq 24 ]; then
        log "WARN" "→ ${PEER}: files partially transferred (code 24 — transient, continuing)"
        FILE_EXIT=0
    fi

    if [ "${FILE_EXIT}" -ne 0 ]; then
        log "ERROR" "→ ${PEER}: files FAILED (exit ${FILE_EXIT}): $(echo "${RSYNC_FILE_OUT}" | tail -1)"
        SYNC_ERRORS=$((SYNC_ERRORS + 1))
        continue
    fi
    log "INFO" "→ ${PEER}: files OK"

    # ── Database snapshot ─────────────────────────────────────────────────────
    # Only the node that owns writes (master) pushes the DB to peers.
    # Mirrors push files only — prevents a fresh mirror DB from clobbering master.
    if [ "${BACKUP_MADE}" -eq 1 ] && [ "${RSYNC_PUSH_DB:-yes}" = "yes" ]; then
        DB_EXIT=0
        rsync \
            --checksum \
            --compress \
            --timeout=30 \
            --rsh="ssh ${SSH_OPTS}" \
            "${BACKUP_DB}" \
            "${REMOTE_USER}@${PEER}:${DATA_DIR}/shimmie.sqlite" >> "${AUDIT_LOG}" 2>&1 || DB_EXIT=$?

        if [ "${DB_EXIT}" -eq 0 ]; then
            # Fix ownership so Apache (www-data) can read the received DB.
            ssh ${SSH_OPTS} "${REMOTE_USER}@${PEER}" \
                "chown www-data:www-data ${DATA_DIR}/shimmie.sqlite 2>/dev/null; true" || true
            log "INFO" "→ ${PEER}: db OK"
        else
            log "WARN" "→ ${PEER}: db sync failed (exit ${DB_EXIT}) — will retry next tick"
        fi
    fi

    log "INFO" "→ ${PEER}: done"
done

# ── Cleanup ───────────────────────────────────────────────────────────────────
rm -f "${BACKUP_DB}"

if [ "${SYNC_ERRORS}" -eq 0 ]; then
    log "INFO" "all peers synced OK"
    exit 0
else
    log "ERROR" "${SYNC_ERRORS} peer(s) failed this round"
    exit 1
fi
