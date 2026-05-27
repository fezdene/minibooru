#!/usr/bin/env python3
"""
integrity_audit.py — Shimmie2 Mirror Node Integrity Audit & Self-Healing
=========================================================================
Scheduled via cron to run every 5 minutes on the Mirror Node.

For every file in data/images/ that has a sha256_hash record in the Shimmie2
SQLite database, this script:
  1. Computes a fresh SHA-256 using buffered reads (safe for large PDFs/videos).
  2. Compares it against the database value inserted at upload time (Task 1).
  3. On mismatch or missing file: logs a CRITICAL alert to /var/log/rsync_audit.log
     (the same file the Network Operations Dashboard reads) and triggers an
     RSYNC pull from the Master Node to restore the corrupted file.

The self-healing loop is intentionally file-scoped: only the affected file is
pulled, not the entire archive, to minimise network load.

Deployment:
  cp integrity_audit.py /opt/scripts/integrity_audit.py
  chmod 700 /opt/scripts/integrity_audit.py
  crontab -e   # add the line shown at the bottom of this file

Requirements (Debian):
  apt-get install python3 rsync
  # SSH key-based auth must be configured from Mirror → Master Node.
"""

from __future__ import annotations

# ── Standard library ──────────────────────────────────────────────────────────
import fcntl
import hashlib
import logging
import os
import re
import sqlite3
import subprocess
import sys
import time
from contextlib import contextmanager
from pathlib import Path
from typing import Iterator, Optional


# ─────────────────────────────────────────────────────────────────────────────
# Auto-detect WH_SPLITS from shimmie.conf.php
# (defined before the constants block so it can be called there)
# ─────────────────────────────────────────────────────────────────────────────

def _read_wh_splits(shimmie_root: Path) -> int:
    """
    Parse the WH_SPLITS PHP constant from data/config/shimmie.conf.php.

    Shimmie2 uses WH_SPLITS to control how many directory levels are created
    inside data/images/.  The value is a PHP define() constant, NOT a database
    config key, so it must be read from the config file.

    Returns 1 (Shimmie2's default) if the constant is absent or the file is
    unreadable.
    """
    conf_path = shimmie_root / "data" / "config" / "shimmie.conf.php"
    if conf_path.is_file():
        try:
            content = conf_path.read_text(encoding="utf-8", errors="replace")
            match = re.search(
                r"""define\s*\(\s*["']WH_SPLITS["']\s*,\s*(\d+)\s*\)""",
                content,
            )
            if match:
                return int(match.group(1))
        except OSError:
            pass
    return 1


# ─────────────────────────────────────────────────────────────────────────────
# CONFIGURATION
# Edit this block to match your Mirror Node deployment.
# ─────────────────────────────────────────────────────────────────────────────

# Absolute path to the Shimmie2 installation root on the Mirror Node.
SHIMMIE_ROOT: Path = Path("/var/www/shimmie")

# Path to the SQLite database (opened read-only by this script).
DB_PATH: Path = SHIMMIE_ROOT / "shimmie.sqlite"

# Physical media directory that will be walked for integrity checks.
DATA_DIR: Path = SHIMMIE_ROOT / "data" / "images"

# Log file shared with the Network Operations Dashboard admin panel.
AUDIT_LOG: Path = Path("/var/log/rsync_audit.log")

# Prevents two audit processes running at the same time (cron overlap).
LOCK_FILE: Path = Path("/tmp/integrity_audit.lock")

# Auto-detected from data/config/shimmie.conf.php; override here if needed.
# 1 → data/images/ab/<hash>   |   2 → data/images/ab/cd/<hash>
WH_SPLITS: int = _read_wh_splits(SHIMMIE_ROOT)

# SSH user on the Master Node with read access to its data/images/ directory.
RSYNC_SSH_USER: str = "root"

# Absolute path to data/images/ on the Master Node (must mirror DATA_DIR layout).
REMOTE_DATA_DIR: str = "/var/www/shimmie/data/images"

# Per-file rsync timeout in seconds.
RSYNC_TIMEOUT_SECS: int = 120

# Read files in 8 MB chunks.  Prevents large PDFs from being loaded into RAM.
# Increase on memory-rich servers; decrease on systems with < 512 MB RAM.
READ_BUFFER_BYTES: int = 8 * 1024 * 1024

# SQLite config table key that holds the Master Node IP/hostname.
# This is set via the Network Operations Dashboard (Task 4) or directly:
#   INSERT OR REPLACE INTO config(name, value)
#   VALUES('network_ops_master_ip', '192.168.1.10');
MASTER_IP_CONFIG_KEY: str = "network_ops_master_ip"


# ─────────────────────────────────────────────────────────────────────────────
# Logging
# ─────────────────────────────────────────────────────────────────────────────

def _build_logger() -> logging.Logger:
    log = logging.getLogger("integrity_audit")
    log.setLevel(logging.DEBUG)

    fmt = logging.Formatter(
        "[%(asctime)s] [%(levelname)-8s] %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )

    # File handler — written to the audit log the Dashboard reads.
    try:
        fh = logging.FileHandler(AUDIT_LOG, mode="a", encoding="utf-8")
        fh.setLevel(logging.INFO)
        fh.setFormatter(fmt)
        log.addHandler(fh)
    except PermissionError:
        print(
            f"WARNING: Cannot write to {AUDIT_LOG}. "
            "Run as root or adjust file permissions.",
            file=sys.stderr,
        )

    # Console handler — captured by cron mailer for WARNING+ alerts.
    ch = logging.StreamHandler(sys.stdout)
    ch.setLevel(logging.WARNING)
    ch.setFormatter(fmt)
    log.addHandler(ch)

    return log


logger = _build_logger()


# ─────────────────────────────────────────────────────────────────────────────
# Utility functions
# ─────────────────────────────────────────────────────────────────────────────

def warehouse_path(data_dir: Path, md5_hash: str, splits: int) -> Path:
    """
    Python port of Shimmie2's Filesystem::warehouse_path().

    With splits=1: data_dir / 'ab' / 'abcdef...'
    With splits=2: data_dir / 'ab' / 'cd' / 'abcdef...'
    """
    path = data_dir
    for i in range(splits):
        path = path / md5_hash[i * 2 : i * 2 + 2]
    return path / md5_hash


def compute_sha256(
    file_path: Path,
    buffer_size: int = READ_BUFFER_BYTES,
) -> Optional[str]:
    """
    Compute the SHA-256 digest of a file using buffered reads.

    Reading in chunks avoids loading entire files (e.g. 50 MB PDFs or
    multi-hundred-MB video archives) into memory at once.  The walrus
    operator requires Python 3.8+.

    Returns lowercase hex string, or None on I/O failure.
    """
    h = hashlib.sha256()
    try:
        with open(file_path, "rb") as fh:
            while chunk := fh.read(buffer_size):
                h.update(chunk)
        return h.hexdigest()
    except OSError as exc:
        logger.error("Cannot read '%s': %s", file_path, exc)
        return None


def get_master_ip(conn: sqlite3.Connection) -> Optional[str]:
    """
    Read the Master Node IP/hostname from the Shimmie2 config table.

    The config table schema is: config(name VARCHAR(128) PK, value TEXT).
    This is the same table that Shimmie2 uses for all settings.
    """
    row = conn.execute(
        "SELECT value FROM config WHERE name = ?",
        (MASTER_IP_CONFIG_KEY,),
    ).fetchone()
    if row and row[0]:
        return str(row[0]).strip()
    return None


RSYNC_PULL_SCRIPT: str = "/opt/scripts/rsync_pull.sh"


def heal_file(master_ip: str, md5_hash: str, local_path: Path) -> bool:
    """
    Restore a corrupted or missing file by delegating to rsync_pull.sh.

    The shell script handles all rsync-over-SSH logic. MASTER_HOST is passed
    as an environment variable so the script stays configurable without
    requiring arguments to change.

    Security:
    - subprocess.run() with a list means shell=False — no shell interpolation
      occurs regardless of master_ip or md5_hash content.
    - md5_hash is a 32-char hex string from the database (validated upstream).
    - master_ip is the admin-configured database value, not HTTP input.

    Returns True if rsync_pull.sh exits 0 and the local file now exists.
    """
    local_path.parent.mkdir(parents=True, exist_ok=True)

    cmd = [RSYNC_PULL_SCRIPT, md5_hash, str(local_path)]

    logger.info("Self-heal: calling rsync_pull.sh for '%s' (master: %s)", md5_hash, master_ip)

    try:
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=RSYNC_TIMEOUT_SECS + 10,
            env={**os.environ, "MASTER_HOST": master_ip},
        )
        if result.returncode == 0 and local_path.is_file():
            return True
        logger.error(
            "Self-heal rsync_pull.sh failed (exit %d): %s",
            result.returncode,
            (result.stderr or result.stdout).strip(),
        )
        return False
    except subprocess.TimeoutExpired:
        logger.error("Self-heal timed out (%ds) for '%s'", RSYNC_TIMEOUT_SECS, md5_hash)
        return False
    except FileNotFoundError:
        logger.error("rsync_pull.sh not found at %s", RSYNC_PULL_SCRIPT)
        return False


@contextmanager
def exclusive_lock(lock_path: Path) -> Iterator[None]:
    """
    Advisory file lock that prevents two audit processes running concurrently.

    If cron fires while a large previous run is still in progress (e.g., on a
    first-run scan of a large archive), the new process exits cleanly rather
    than duplicating work or racing on rsync writes.
    """
    try:
        lock_fh = open(lock_path, "w")
    except OSError as exc:
        logger.error("Cannot open lock file %s: %s", lock_path, exc)
        sys.exit(1)

    try:
        fcntl.flock(lock_fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except IOError:
        logger.warning(
            "Another audit instance is already running (lock: %s). Exiting.", lock_path
        )
        lock_fh.close()
        sys.exit(0)

    try:
        yield
    finally:
        fcntl.flock(lock_fh, fcntl.LOCK_UN)
        lock_fh.close()


# ─────────────────────────────────────────────────────────────────────────────
# Main audit routine
# ─────────────────────────────────────────────────────────────────────────────

def run_audit() -> None:
    start = time.monotonic()
    logger.info("━" * 60)
    logger.info("Integrity Audit STARTED  [WH_SPLITS=%d  DB=%s]", WH_SPLITS, DB_PATH)
    logger.info("━" * 60)

    # ── Validate environment ─────────────────────────────────────────────────
    if not DB_PATH.is_file():
        logger.critical("Database not found: %s — aborting.", DB_PATH)
        sys.exit(1)

    if not DATA_DIR.is_dir():
        logger.critical("Data directory not found: %s — aborting.", DATA_DIR)
        sys.exit(1)

    # ── Connect to SQLite (read-only) ────────────────────────────────────────
    # Using the ?mode=ro URI prevents the audit script from ever writing to the
    # live database, even if a bug would otherwise cause an accidental write.
    conn = sqlite3.connect(f"file:{DB_PATH}?mode=ro", uri=True)
    conn.row_factory = sqlite3.Row

    master_ip = get_master_ip(conn)
    if not master_ip:
        logger.warning(
            "Master Node IP not set (config key: '%s'). "
            "Integrity mismatches will be logged but NOT healed.",
            MASTER_IP_CONFIG_KEY,
        )

    # ── Fetch auditable records from DB ─────────────────────────────────────
    # Only rows with a non-null sha256_hash are auditable.  Rows uploaded
    # before the Task 1 migration have sha256_hash = NULL and are counted
    # separately.
    rows = conn.execute(
        """
        SELECT  hash,
                sha256_hash,
                filename
        FROM    images
        WHERE   sha256_hash IS NOT NULL
          AND   sha256_hash != ''
        """
    ).fetchall()

    no_sha256_count: int = conn.execute(
        "SELECT COUNT(*) FROM images WHERE sha256_hash IS NULL OR sha256_hash = ''"
    ).fetchone()[0]

    conn.close()

    # md5_hash → {'sha256_hash': str, 'filename': str}
    db_records: dict[str, dict] = {
        str(row["hash"]).lower(): {
            "sha256_hash": str(row["sha256_hash"]).lower(),
            "filename":    str(row["filename"]),
        }
        for row in rows
    }

    logger.info(
        "Database: %d auditable records | %d skipped (no sha256_hash — pre-migration)",
        len(db_records),
        no_sha256_count,
    )

    # ── Walk data/images/ and audit each file ────────────────────────────────
    counts = {
        "ok":              0,
        "corrupted":       0,
        "orphan":          0,   # on disk but not in DB
        "healed":          0,
        "heal_failed":     0,
        "unreadable":      0,
    }
    # Track which MD5 hashes we found on disk (to detect missing-from-disk later).
    checked_md5s: set[str] = set()

    for file_path in DATA_DIR.rglob("*"):
        if not file_path.is_file():
            continue

        md5_hash = file_path.name.lower()

        # Shimmie2 stores files with their MD5 hash as the sole filename.
        # Any file whose name is not a 32-char hex string is not a media file.
        if len(md5_hash) != 32 or not all(c in "0123456789abcdef" for c in md5_hash):
            logger.debug("Non-hash file skipped: %s", file_path)
            continue

        checked_md5s.add(md5_hash)

        # ── File exists on disk but has no DB record ─────────────────────
        if md5_hash not in db_records:
            counts["orphan"] += 1
            logger.warning("ORPHAN (on disk, not in DB): %s", file_path)
            continue

        expected_sha256 = db_records[md5_hash]["sha256_hash"]
        filename        = db_records[md5_hash]["filename"]

        # ── Compute fresh SHA-256 (buffered) ─────────────────────────────
        actual_sha256 = compute_sha256(file_path)
        if actual_sha256 is None:
            counts["unreadable"] += 1
            continue

        # ── Integrity check ───────────────────────────────────────────────
        if actual_sha256 == expected_sha256:
            counts["ok"] += 1
            logger.debug("OK  %-40s  %s", filename, md5_hash)
            continue

        # ── MISMATCH — Tampering / Bit-rot ────────────────────────────────
        counts["corrupted"] += 1
        logger.critical(
            "INTEGRITY MISMATCH  "
            "file='%s'  md5='%s'  "
            "db_sha256='%s'  actual_sha256='%s'",
            filename, md5_hash, expected_sha256, actual_sha256,
        )

        _attempt_heal(
            master_ip, md5_hash, file_path,
            expected_sha256, filename, counts,
        )

    # ── Check for DB records whose file is absent from disk ─────────────────
    missing_md5s = set(db_records.keys()) - checked_md5s
    for md5_hash in sorted(missing_md5s):
        filename        = db_records[md5_hash]["filename"]
        expected_sha256 = db_records[md5_hash]["sha256_hash"]
        expected_path   = warehouse_path(DATA_DIR, md5_hash, WH_SPLITS)

        logger.critical(
            "FILE MISSING FROM DISK  "
            "file='%s'  md5='%s'  expected_path='%s'",
            filename, md5_hash, expected_path,
        )

        _attempt_heal(
            master_ip, md5_hash, expected_path,
            expected_sha256, filename, counts,
        )

    # ── Audit summary ────────────────────────────────────────────────────────
    elapsed = time.monotonic() - start
    total_files = counts["ok"] + counts["corrupted"] + counts["orphan"] + counts["unreadable"]

    logger.info(
        "AUDIT COMPLETE in %.2fs  |  "
        "Files scanned: %d  |  OK: %d  |  "
        "Corrupted: %d  |  Missing: %d  |  "
        "Orphaned: %d  |  Unreadable: %d  |  "
        "Healed: %d  |  Unresolved: %d",
        elapsed, total_files,
        counts["ok"],
        counts["corrupted"],
        len(missing_md5s),
        counts["orphan"],
        counts["unreadable"],
        counts["healed"],
        counts["heal_failed"],
    )
    logger.info("━" * 60)


def _attempt_heal(
    master_ip: Optional[str],
    md5_hash: str,
    local_path: Path,
    expected_sha256: str,
    filename: str,
    counts: dict[str, int],
) -> None:
    """
    Helper shared by the corruption path and the missing-file path.
    Attempts an RSYNC pull from the Master Node and verifies the result.
    Updates `counts` in-place.
    """
    if not master_ip:
        counts["heal_failed"] += 1
        logger.error(
            "Self-heal skipped for '%s': Master Node IP is not configured.",
            md5_hash,
        )
        return

    if heal_file(master_ip, md5_hash, local_path):
        # Post-heal verification — re-hash the restored file
        verified_sha256 = compute_sha256(local_path)
        if verified_sha256 == expected_sha256:
            counts["healed"] += 1
            logger.info(
                "Self-heal VERIFIED  file='%s'  md5='%s'  restored from %s",
                filename, md5_hash, master_ip,
            )
        else:
            counts["heal_failed"] += 1
            logger.critical(
                "Self-heal VERIFICATION FAILED  "
                "file='%s'  md5='%s'  "
                "hash still incorrect after rsync.",
                filename, md5_hash,
            )
    else:
        counts["heal_failed"] += 1
        logger.critical(
            "Self-heal RSYNC FAILED  file='%s'  md5='%s'",
            filename, md5_hash,
        )


# ─────────────────────────────────────────────────────────────────────────────
# Entry point
# ─────────────────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    with exclusive_lock(LOCK_FILE):
        run_audit()


# ─────────────────────────────────────────────────────────────────────────────
# DEPLOYMENT INSTRUCTIONS
# ─────────────────────────────────────────────────────────────────────────────
#
# 1. Copy to the Mirror Node:
#       cp integrity_audit.py /opt/scripts/integrity_audit.py
#       chmod 700 /opt/scripts/integrity_audit.py
#       chown root:root /opt/scripts/integrity_audit.py
#
# 2. Ensure the audit log file is writable by root (or whichever user runs cron):
#       touch /var/log/rsync_audit.log
#       chmod 640 /var/log/rsync_audit.log
#
# 3. Store the Master Node IP in the Mirror's Shimmie2 database:
#       sqlite3 /var/www/shimmie/shimmie.sqlite \
#         "INSERT OR REPLACE INTO config(name, value) \
#          VALUES('network_ops_master_ip', '192.168.1.10');"
#
# 4. Configure passwordless SSH from Mirror → Master (if not already done):
#       ssh-keygen -t ed25519 -f /root/.ssh/id_mirror_audit -N ""
#       ssh-copy-id -i /root/.ssh/id_mirror_audit.pub root@192.168.1.10
#
# 5. Test a single run before scheduling:
#       python3 /opt/scripts/integrity_audit.py
#       tail -20 /var/log/rsync_audit.log
#
# 6. Schedule in root's crontab (every 5 minutes — OSI Layer 3/4 demo cadence):
#       crontab -e
#
#       Add this line:
#       */5 * * * * /usr/bin/python3 /opt/scripts/integrity_audit.py
#
#       To suppress all console output (cron email) and rely solely on the log:
#       */5 * * * * /usr/bin/python3 /opt/scripts/integrity_audit.py >/dev/null 2>&1
#
# 7. Verify the cron entry is active:
#       crontab -l
#       grep CRON /var/log/syslog | tail -5
#
# NOTE ON WH_SPLITS:
#   This script auto-reads WH_SPLITS from data/config/shimmie.conf.php.
#   If the file is absent (common in Docker), it defaults to 1.
#   Override manually at the top of this script if needed.
