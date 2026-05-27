#!/bin/bash
# rsync_daemon.sh — Runs rsync_mesh.sh every 30 seconds.
# Started in the background by each node's entrypoint script.
#
# Behaviour:
#   • Calls rsync_mesh.sh on each tick.
#   • rsync_mesh.sh carries its own flock guard (exit 2 = already running).
#     If a sync takes longer than 30 s, the next tick is silently skipped.
#   • Sleeps the remainder of the 30-second interval (not a fixed 30 s after
#     the sync completes) so the schedule stays approximately wall-clock aligned.

MESH_SCRIPT="/opt/scripts/rsync_mesh.sh"
AUDIT_LOG="/var/log/rsync_audit.log"
INTERVAL=30

ts() { TZ=Asia/Kuala_Lumpur date '+%Y-%m-%d %H:%M:%S'; }

# Give entrypoints time to finish SSH key setup and sshd start before the
# first sync attempt.
sleep 10

echo "[$(ts)] [INFO] rsync_daemon: started on $(hostname) (interval=${INTERVAL}s)" >> "${AUDIT_LOG}"

while true; do
    TICK_START=$(date +%s)

    if [ -x "${MESH_SCRIPT}" ]; then
        "${MESH_SCRIPT}" || EXIT=$?
        EXIT="${EXIT:-0}"
        if [ "${EXIT}" -eq 2 ]; then
            echo "[$(ts)] [WARN] rsync_daemon: previous sync still running, skipped tick." >> "${AUDIT_LOG}"
        fi
        # exit 0 = success, exit 1 = partial errors (already logged by mesh script)
        # exit 2 = lock busy (logged above) — all are non-fatal for the daemon
        EXIT=0
    else
        echo "[$(ts)] [ERROR] rsync_daemon: ${MESH_SCRIPT} not found or not executable." >> "${AUDIT_LOG}"
    fi

    # Sleep only the time remaining in the interval so we don't drift.
    ELAPSED=$(( $(date +%s) - TICK_START ))
    SLEEP_FOR=$(( INTERVAL - ELAPSED ))
    if [ "${SLEEP_FOR}" -gt 0 ]; then
        sleep "${SLEEP_FOR}"
    fi
done
