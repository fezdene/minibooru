#!/bin/bash
# entrypoint.mirror.sh — Mirror Node startup script (mirror-1 and mirror-2)
# Starts: SSH daemon → cron daemon → mesh sync daemon → Apache (foreground, PID 1).
# Runs as PID 1 inside each mirror container.
set -euo pipefail

NODE_LABEL="$(hostname)"

# ── SSH host key generation ───────────────────────────────────────────────────
echo "[entrypoint.mirror(${NODE_LABEL})] Generating SSH host keys..."
ssh-keygen -A
mkdir -p /run/sshd

# ── SSH keys ──────────────────────────────────────────────────────────────────
# authorized_keys: allows master (and other mirrors) to SSH in for RSYNC push.
AUTHKEYS="/root/.ssh/authorized_keys"
if [ -f "${AUTHKEYS}" ]; then
    chmod 600 "${AUTHKEYS}"
    echo "[entrypoint.mirror(${NODE_LABEL})] authorized_keys found and permissions set."
else
    echo "[entrypoint.mirror(${NODE_LABEL})] WARNING: ${AUTHKEYS} not found." \
         "Copy ./ssh_keys/id_archive.pub → ./ssh_keys/authorized_keys on the host."
fi

# id_archive private key: used by rsync_pull.sh to SSH to master for self-healing.
PULL_KEY="/root/.ssh/id_archive"
if [ -f "${PULL_KEY}" ]; then
    chmod 600 "${PULL_KEY}"
    echo "[entrypoint.mirror(${NODE_LABEL})] id_archive private key found — rsync_pull.sh ready."
else
    echo "[entrypoint.mirror(${NODE_LABEL})] WARNING: ${PULL_KEY} not found." \
         "Mount ./ssh_keys/id_archive into this container for self-healing to work."
fi

# ── Shimmie2 first-run install ────────────────────────────────────────────────
# Same logic as entrypoint.master.sh — see comments there.
CONF_DIR="/var/www/shimmie/data/config"
CONF_FILE="${CONF_DIR}/shimmie.conf.php"

mkdir -p "${CONF_DIR}"

if [ ! -f "${CONF_FILE}" ]; then
    DB_DSN="${DATABASE_DSN:-sqlite:/var/www/shimmie/data/shimmie.sqlite}"
    echo "[entrypoint.mirror(${NODE_LABEL})] First run — initialising Shimmie2 database..."
    cd /var/www/shimmie
    INSTALL_DSN="${DB_DSN}" php index.php
    echo "[entrypoint.mirror(${NODE_LABEL})] Database initialised."
fi

# ── DB migrations ─────────────────────────────────────────────────────────────
# Run on every boot — Shimmie2's migration system is idempotent (checks current
# version and only applies newer steps). Ensures theme=modernbooru and the full
# extensions list are always set, even before the first RSYNC push from master.
echo "[entrypoint.mirror(${NODE_LABEL})] Running DB migrations..."
cd /var/www/shimmie
php index.php db-upgrade
echo "[entrypoint.mirror(${NODE_LABEL})] DB migrations done."

# ── Data directory skeleton ───────────────────────────────────────────────────
mkdir -p \
    /var/www/shimmie/data/images \
    /var/www/shimmie/data/thumbs \
    /var/www/shimmie/data/cache \
    /var/www/shimmie/data/config \
    /tmp/ingest

chown -R www-data:www-data /var/www/shimmie/data /tmp/ingest
chmod -R 755 /var/www/shimmie/data

# ── Audit log ─────────────────────────────────────────────────────────────────
touch /var/log/rsync_audit.log
chmod 644 /var/log/rsync_audit.log

# ── SSH daemon ────────────────────────────────────────────────────────────────
echo "[entrypoint.mirror(${NODE_LABEL})] Starting SSH daemon..."
/usr/sbin/sshd
echo "[entrypoint.mirror(${NODE_LABEL})] sshd started."

# ── Integrity audit cron job ──────────────────────────────────────────────────
CRON_JOB="*/15 * * * * /usr/bin/python3 /opt/scripts/integrity_audit.py >> /var/log/rsync_audit.log 2>&1 && chmod 644 /var/log/rsync_audit.log"
( crontab -l 2>/dev/null | grep -qF "integrity_audit.py" ) \
    || ( crontab -l 2>/dev/null; echo "${CRON_JOB}" ) | crontab -
echo "[entrypoint.mirror(${NODE_LABEL})] Integrity audit cron job registered."

# ── Cron daemon ───────────────────────────────────────────────────────────────
echo "[entrypoint.mirror(${NODE_LABEL})] Starting cron daemon..."
/usr/sbin/cron

# ── Apache (foreground — keeps the container alive) ──────────────────────────
echo "[entrypoint.mirror(${NODE_LABEL})] Starting Apache..."
exec apache2-foreground
