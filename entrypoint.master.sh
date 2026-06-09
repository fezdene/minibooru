#!/bin/bash
# entrypoint.master.sh — Master Node startup script
# Runs as PID 1 inside the minibooru-master container.
set -euo pipefail

# ── Shimmie2 first-run install ────────────────────────────────────────────────
# On the very first start the SQLite database has no tables.  Run Shimmie2's
# own CLI installer (INSTALL_DSN triggers non-interactive mode) so it creates
# the schema AND writes data/config/shimmie.conf.php with a generated SECRET.
# Subsequent starts find the conf file and skip this block entirely.
CONF_DIR="/var/www/shimmie/data/config"
CONF_FILE="${CONF_DIR}/shimmie.conf.php"

mkdir -p "${CONF_DIR}"

if [ ! -f "${CONF_FILE}" ]; then
    DB_DSN="${DATABASE_DSN:-sqlite:/var/www/shimmie/data/shimmie.sqlite}"
    echo "[entrypoint.master] First run — initialising Shimmie2 database..."
    cd /var/www/shimmie
    INSTALL_DSN="${DB_DSN}" php index.php
    echo "[entrypoint.master] Database initialised."
fi

# ── DB migrations ─────────────────────────────────────────────────────────────
echo "[entrypoint.master] Running DB migrations..."
cd /var/www/shimmie
php index.php db-upgrade
echo "[entrypoint.master] DB migrations done."

# ── Data directory skeleton ───────────────────────────────────────────────────
# Volume mounts shadow the Dockerfile's mkdir -p, so we recreate required dirs
# here every boot — idempotent and safe.
mkdir -p \
    /var/www/shimmie/data/images \
    /var/www/shimmie/data/thumbs \
    /var/www/shimmie/data/cache \
    /var/www/shimmie/data/config \
    /tmp/ingest

chown -R www-data:www-data /var/www/shimmie/data /tmp/ingest
chmod -R 755 /var/www/shimmie/data

# ── SSH private key ───────────────────────────────────────────────────────────
# SSH refuses to use a key file that is group- or world-readable.
KEY="/root/.ssh/id_archive"
if [ -f "${KEY}" ]; then
    chmod 600 "${KEY}"
    # Also make a www-data-accessible copy so PHP (running as www-data) can
    # trigger rsync_push.sh without needing root access to /root/.ssh/.
    mkdir -p /var/www/.ssh
    cp "${KEY}" /var/www/.ssh/id_archive
    chown -R www-data:www-data /var/www/.ssh
    chmod 700 /var/www/.ssh
    chmod 600 /var/www/.ssh/id_archive
    echo "[entrypoint.master] SSH private key permissions set (root + www-data)."
else
    echo "[entrypoint.master] WARNING: ${KEY} not found. Mesh sync will fail." \
         "Mount ./ssh_keys/id_archive into the container."
fi

# ── SSH server (accepts rsync_pull connections from Mirror Nodes) ─────────────
# Mirrors SSH to master to pull individual files during self-healing.
AUTHKEYS="/root/.ssh/authorized_keys"
if [ -f "${AUTHKEYS}" ]; then
    chmod 600 "${AUTHKEYS}"
    echo "[entrypoint.master] authorized_keys found — mirrors can pull via SSH."
fi
echo "[entrypoint.master] Generating SSH host keys..."
ssh-keygen -A 2>/dev/null || true
mkdir -p /run/sshd
echo "[entrypoint.master] Starting SSH daemon (for rsync_pull from mirrors)..."
/usr/sbin/sshd
echo "[entrypoint.master] sshd started."

# ── Audit log ─────────────────────────────────────────────────────────────────
touch /var/log/rsync_audit.log 2>/dev/null || true
chown root:www-data /var/log/rsync_audit.log 2>/dev/null || true
chmod 664 /var/log/rsync_audit.log 2>/dev/null || true

# ── Auto-sync daemon ──────────────────────────────────────────────────────────
# Pushes data to all configured mirrors every $RSYNC_INTERVAL seconds.
# Only started when RSYNC_MIRRORS is non-empty (set in docker-compose.yml).
chmod +x /opt/scripts/*.sh 2>/dev/null || true
DAEMON="/opt/scripts/rsync_push_daemon.sh"
if [ -n "${RSYNC_MIRRORS:-}" ]; then
    if [ -f "${DAEMON}" ]; then
        chmod +x "${DAEMON}"
        RSYNC_MIRRORS="${RSYNC_MIRRORS}" RSYNC_INTERVAL="${RSYNC_INTERVAL:-30}" \
            "${DAEMON}" >> /var/log/rsync_audit.log 2>&1 &
        echo "[entrypoint.master] Auto-sync daemon started (mirrors: ${RSYNC_MIRRORS}, interval: ${RSYNC_INTERVAL:-30}s)."
    else
        echo "[entrypoint.master] WARNING: ${DAEMON} not found. Auto-sync disabled."
    fi
else
    echo "[entrypoint.master] RSYNC_MIRRORS not set — auto-sync daemon not started."
fi

echo "[entrypoint.master] Starting Apache..."
exec apache2-foreground
