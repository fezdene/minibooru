#!/bin/bash
# rsync_push_daemon.sh — Auto-push daemon: master → all mirrors every N seconds
#
# Started by entrypoint.master.sh when RSYNC_MIRRORS is set.
# Reads from environment:
#   RSYNC_MIRRORS  Comma-separated mirror hostnames (e.g. "mirror-node,mirror-2")
#   RSYNC_INTERVAL Seconds between push cycles (default: 30)

PUSH_SCRIPT="/opt/scripts/rsync_push.sh"
AUDIT_LOG="/var/log/rsync_audit.log"
INTERVAL="${RSYNC_INTERVAL:-30}"

# ── Validate ──────────────────────────────────────────────────────────────────
if [ -z "${RSYNC_MIRRORS:-}" ]; then
    echo "[rsync_push_daemon] RSYNC_MIRRORS not set — daemon not needed. Exiting."
    exit 0
fi

if [ ! -x "${PUSH_SCRIPT}" ]; then
    echo "[rsync_push_daemon] ERROR: ${PUSH_SCRIPT} not found or not executable. Exiting."
    exit 1
fi

# ── Parse mirror list ─────────────────────────────────────────────────────────
IFS=',' read -ra RAW_MIRRORS <<< "${RSYNC_MIRRORS}"
MIRRORS=()
for M in "${RAW_MIRRORS[@]}"; do
    M="${M// /}"   # trim spaces
    [ -z "${M}" ] && continue
    # Accept only safe hostname characters (no shell metacharacters)
    if ! echo "${M}" | grep -qP '^[a-zA-Z0-9.\-]+$'; then
        echo "[rsync_push_daemon] WARNING: skipping invalid mirror name '${M}'"
        continue
    fi
    MIRRORS+=("${M}")
done

if [ ${#MIRRORS[@]} -eq 0 ]; then
    echo "[rsync_push_daemon] No valid mirrors after parsing '${RSYNC_MIRRORS}'. Exiting."
    exit 0
fi

echo "[rsync_push_daemon] Starting. Mirrors: ${MIRRORS[*]}. Interval: ${INTERVAL}s."

# ── Main loop ─────────────────────────────────────────────────────────────────
while true; do
    TIMESTAMP="$(TZ=Asia/Kuala_Lumpur date '+%Y-%m-%d %H:%M:%S')"

    for MIRROR in "${MIRRORS[@]}"; do
        echo "[${TIMESTAMP}] [rsync_push_daemon] Dispatching push → ${MIRROR}"
        # Run each push in its own background subshell so slow transfers to one
        # mirror do not delay the push to the next mirror.
        (
            "${PUSH_SCRIPT}" "${MIRROR}" >> "${AUDIT_LOG}" 2>&1
        ) &
    done

    # Wait for all background pushes from this cycle before sleeping.
    # This prevents unbounded process accumulation when transfers are slow.
    wait

    sleep "${INTERVAL}"
done
