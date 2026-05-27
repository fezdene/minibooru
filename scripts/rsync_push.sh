#!/bin/bash
# rsync_push.sh — Master-to-Mirror RSYNC push
# Called by the Network Operations Dashboard and can be run manually.
#
# Usage: rsync_push.sh <mirror_ip_or_hostname>
#
# The caller (PHP's shell_exec) is responsible for passing the IP through
# escapeshellarg().  This script treats $1 as a trusted value but still
# validates it to prevent misuse if called directly.

set -euo pipefail

MIRROR_HOST="${1:-}"
REMOTE_USER="root"
REMOTE_DIR="/var/www/shimmie/data"
LOCAL_DIR="/var/www/shimmie/data"
LOCAL_DB="/var/www/shimmie/data/shimmie.sqlite"
BACKUP_DB="/tmp/rsync_db_snapshot_$$.sqlite"
RECEIPT_FILE="/tmp/rsync_receipt_$$.json"
SSH_KEY="/var/www/.ssh/id_archive"
AUDIT_LOG="/var/log/rsync_audit.log"
SSH_OPTS="-i ${SSH_KEY} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR"

TIMESTAMP="$(TZ=Asia/Kuala_Lumpur date '+%Y-%m-%d %H:%M:%S')"
SOURCE_HOST="$(hostname)"

# ── Input validation ──────────────────────────────────────────────────────────
if [ -z "${MIRROR_HOST}" ]; then
    echo "[${TIMESTAMP}] [ERROR] rsync_push.sh: no mirror host provided." | tee -a "${AUDIT_LOG}"
    exit 1
fi

if echo "${MIRROR_HOST}" | grep -qP '[^a-zA-Z0-9.\-]'; then
    echo "[${TIMESTAMP}] [ERROR] rsync_push.sh: invalid mirror host '${MIRROR_HOST}'." | tee -a "${AUDIT_LOG}"
    exit 1
fi

# ── Safe DB snapshot ──────────────────────────────────────────────────────────
# sqlite3 .backup acquires a shared lock and produces a consistent copy even
# under active writes — avoids transferring a partially-written journal file.
if [ -f "${LOCAL_DB}" ]; then
    sqlite3 "${LOCAL_DB}" ".backup ${BACKUP_DB}"
    BACKUP_MADE=1
else
    BACKUP_MADE=0
fi

# ── RSYNC push (media files only — live DB excluded) ─────────────────────────
echo "[${TIMESTAMP}] [INFO] rsync_push started → ${MIRROR_HOST}" | tee -a "${AUDIT_LOG}"

RSYNC_OUT=$(rsync \
    --archive \
    --compress \
    --delete \
    --checksum \
    --partial \
    --timeout=120 \
    --stats \
    --exclude='shimmie.sqlite' \
    --exclude='shimmie.sqlite-journal' \
    --rsh="ssh ${SSH_OPTS}" \
    "${LOCAL_DIR}/" \
    "${REMOTE_USER}@${MIRROR_HOST}:${REMOTE_DIR}/" 2>&1)

FILE_RSYNC_EXIT=$?
echo "${RSYNC_OUT}" | tee -a "${AUDIT_LOG}"

# Parse transfer stats from rsync --stats output
FILES_SENT=$(echo "${RSYNC_OUT}" | grep -oP 'Number of regular files transferred:\s*\K[\d,]+' | tr -d ',' || echo "0")
BYTES_SENT=$(echo "${RSYNC_OUT}" | grep -oP 'Total transferred file size:\s*\K[\d,]+' | tr -d ',' || echo "0")
FILES_SENT="${FILES_SENT:-0}"
BYTES_SENT="${BYTES_SENT:-0}"

# ── Transfer DB snapshot separately ──────────────────────────────────────────
DB_RSYNC_EXIT=0
if [ "${BACKUP_MADE}" -eq 1 ]; then
    rsync \
        --checksum \
        --compress \
        --timeout=120 \
        --rsh="ssh ${SSH_OPTS}" \
        "${BACKUP_DB}" \
        "${REMOTE_USER}@${MIRROR_HOST}:${REMOTE_DIR}/shimmie.sqlite" 2>&1 | tee -a "${AUDIT_LOG}"
    DB_RSYNC_EXIT=$?
    rm -f "${BACKUP_DB}"
    # Fix ownership so Apache (www-data) can write to the synced DB
    ssh ${SSH_OPTS} "${REMOTE_USER}@${MIRROR_HOST}" \
        "chown www-data:www-data ${REMOTE_DIR}/shimmie.sqlite" 2>/dev/null || true
fi

TIMESTAMP="$(TZ=Asia/Kuala_Lumpur date '+%Y-%m-%d %H:%M:%S')"

if [ "${FILE_RSYNC_EXIT}" -eq 0 ] && [ "${DB_RSYNC_EXIT}" -eq 0 ]; then
    SYNC_STATUS="ok"
    echo "[${TIMESTAMP}] [INFO] rsync_push COMPLETE → ${MIRROR_HOST} (exit 0)" | tee -a "${AUDIT_LOG}"
else
    SYNC_STATUS="error"
    echo "[${TIMESTAMP}] [ERROR] rsync_push FAILED → ${MIRROR_HOST} (file_exit=${FILE_RSYNC_EXIT} db_exit=${DB_RSYNC_EXIT})" | tee -a "${AUDIT_LOG}"
    rm -f "${BACKUP_DB}"
fi

# ── Write receipt to mirror ───────────────────────────────────────────────────
# Mirror reads this file to display when it last received a push from master.
printf '{"synced_at":"%s","source":"%s","mirror":"%s","files_count":%s,"bytes":%s,"status":"%s"}' \
    "${TIMESTAMP}" "${SOURCE_HOST}" "${MIRROR_HOST}" \
    "${FILES_SENT}" "${BYTES_SENT}" "${SYNC_STATUS}" \
    > "${RECEIPT_FILE}"

rsync \
    --timeout=30 \
    --rsh="ssh ${SSH_OPTS}" \
    "${RECEIPT_FILE}" \
    "${REMOTE_USER}@${MIRROR_HOST}:/var/www/shimmie/config/rsync_receipt.json" 2>/dev/null || true

rm -f "${RECEIPT_FILE}"

[ "${SYNC_STATUS}" = "ok" ] && exit 0 || exit 1
