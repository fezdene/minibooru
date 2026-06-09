#!/bin/bash
# rsync_pull.sh — Self-healing: pull a single corrupted/missing file from the Master Node.
#
# Called by integrity_audit.py (via subprocess.run) when a SHA-256 mismatch
# or missing file is detected on this Mirror Node.
#
# Usage:
#   rsync_pull.sh <md5_hash> <local_path>
#
#   md5_hash    32-character hex MD5 hash (Shimmie2 warehouse filename)
#   local_path  Absolute destination path on this mirror
#
# Exit codes:  0 = file restored successfully
#              1 = validation error, SSH failure, or post-pull file missing
#
# SSH key:     /root/.ssh/id_archive  (mounted from ./ssh_keys/id_archive)
# Master host: master-node  (Docker bridge DNS — override with MASTER_HOST env var)

set -euo pipefail

# ── Config ─────────────────────────────────────────────────────────────────────
MASTER_HOST="${MASTER_HOST:-master-node}"
REMOTE_USER="root"
REMOTE_DATA_DIR="/var/www/shimmie/data/images"
SSH_KEY="/root/.ssh/id_archive"
AUDIT_LOG="/var/log/rsync_audit.log"
RSYNC_TIMEOUT=120
# WH_SPLITS=1 matches Shimmie2's default warehouse layout (data/images/ab/<hash>).
# Change to 2 if your installation uses data/images/ab/cd/<hash>.
WH_SPLITS=1

# ── Arguments ──────────────────────────────────────────────────────────────────
MD5_HASH="${1:-}"
LOCAL_PATH="${2:-}"

TIMESTAMP="$(TZ=Asia/Kuala_Lumpur date '+%Y-%m-%d %H:%M:%S')"

# ── Input validation ───────────────────────────────────────────────────────────
if [ -z "${MD5_HASH}" ] || [ -z "${LOCAL_PATH}" ]; then
    echo "[${TIMESTAMP}] [ERROR] rsync_pull.sh: usage: rsync_pull.sh <md5_hash> <local_path>" \
        | tee -a "${AUDIT_LOG}"
    exit 1
fi

# MD5 hash must be exactly 32 lowercase hex chars — no shell metacharacters possible.
if ! printf '%s' "${MD5_HASH}" | grep -qP '^[0-9a-f]{32}$'; then
    echo "[${TIMESTAMP}] [ERROR] rsync_pull.sh: invalid md5_hash '${MD5_HASH}'" \
        | tee -a "${AUDIT_LOG}"
    exit 1
fi

# ── Build remote warehouse path ────────────────────────────────────────────────
# Mirror Shimmie2's warehouse_path() logic.
#   WH_SPLITS=1 → data/images/ab/<hash>
#   WH_SPLITS=2 → data/images/ab/cd/<hash>
REMOTE_PATH="${REMOTE_DATA_DIR}"
for (( i=0; i<WH_SPLITS; i++ )); do
    SEGMENT="${MD5_HASH:$((i*2)):2}"
    REMOTE_PATH="${REMOTE_PATH}/${SEGMENT}"
done
REMOTE_PATH="${REMOTE_PATH}/${MD5_HASH}"
REMOTE_FULL="${REMOTE_USER}@${MASTER_HOST}:${REMOTE_PATH}"

# ── Ensure local parent directory exists ──────────────────────────────────────
LOCAL_DIR="$(dirname "${LOCAL_PATH}")"
mkdir -p "${LOCAL_DIR}"

# ── SSH options ────────────────────────────────────────────────────────────────
RSYNC_SSH_PORT="${RSYNC_SSH_PORT:-22}"
SSH_OPTS="-i ${SSH_KEY} -p ${RSYNC_SSH_PORT} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
-o LogLevel=ERROR -o ConnectTimeout=10 -o BatchMode=yes"

echo "[${TIMESTAMP}] [INFO] rsync_pull: '${MD5_HASH}' ← ${MASTER_HOST}" \
    | tee -a "${AUDIT_LOG}"

# ── RSYNC pull ─────────────────────────────────────────────────────────────────
rsync \
    --archive \
    --inplace \
    --partial \
    --checksum \
    --timeout="${RSYNC_TIMEOUT}" \
    --rsh="ssh ${SSH_OPTS}" \
    "${REMOTE_FULL}" \
    "${LOCAL_PATH}" \
    >> "${AUDIT_LOG}" 2>&1

RSYNC_EXIT=$?
TIMESTAMP="$(TZ=Asia/Kuala_Lumpur date '+%Y-%m-%d %H:%M:%S')"

# ── Report result ──────────────────────────────────────────────────────────────
if [ "${RSYNC_EXIT}" -eq 0 ] && [ -f "${LOCAL_PATH}" ]; then
    echo "[${TIMESTAMP}] [INFO] rsync_pull: SUCCESS '${MD5_HASH}' → ${LOCAL_PATH}" \
        | tee -a "${AUDIT_LOG}"
    exit 0
else
    echo "[${TIMESTAMP}] [ERROR] rsync_pull: FAILED '${MD5_HASH}' (rsync exit=${RSYNC_EXIT})" \
        | tee -a "${AUDIT_LOG}"
    exit 1
fi
