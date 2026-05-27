# Distributed Web Archiving System

**Final Year Project by MUHAMAD HAFIZUDDIN BIN HAMSANI, Universiti Teknologi MARA (UiTM)**

A self-hosted, distributed media archive built on top of [Shimmie2](https://github.com/shish/shimmie2), designed to combat link rot by preserving web content across a multi-node RSYNC replication network with SHA-256 integrity verification and self-healing.

---

## Architecture

```
host
├── port 8080 ──► master-node    (admin — ingest, manage)
├── port 80   ──► mirror-node    (public read-only replica)
└── port 8081 ──► mirror-2       (secondary public replica)

master-node ──RSYNC every 30 s──► mirror-node
            ──RSYNC every 30 s──► mirror-2
```

- **Master node** — ingests media via gallery-dl, yt-dlp, or SingleFile; hashes files with SHA-256; replicates to mirrors.
- **Mirror nodes** — read-only replicas; accept RSYNC pushes; run a self-healing integrity audit every 5 minutes.
- **Database** — SQLite per node, synced as part of the RSYNC payload.

---

## Features

| Feature | Description |
|---|---|
| Multiplatform ingest | gallery-dl → yt-dlp → SingleFile cascade (auto mode) |
| Webpage archiving | SingleFile + Chromium saves any URL as HTML or PDF |
| SHA-256 integrity | Every file is hashed on upload and verified on mirrors |
| Self-healing | Integrity audit detects corruption and pulls clean copy from master |
| Network dashboard | Live mirror probe, RSYNC audit log, sync analytics |
| Bulk management | Mass delete, permission manager, featured post |

---

## Tech Stack

| Layer | Technology |
|---|---|
| Application | PHP 8.4, Shimmie2 2.12.2 |
| Web server | Apache 2.4 + mod_rewrite |
| Database | SQLite 3 |
| Containerisation | Docker + Docker Compose |
| Replication | RSYNC over SSH (key-based auth) |
| Ingest | gallery-dl 1.32.1, yt-dlp 2026.03.17 |
| Webpage archiving | SingleFile CLI 2.0.83 + Chromium 148 |
| Media processing | ffmpeg 7.1, ImageMagick 7.1 |
| Integrity | SHA-256 (Python hashlib, buffered reads) |

---

## Prerequisites

- Docker Desktop (or Docker Engine + Compose plugin)
- 4 GB RAM recommended
- Ports 80, 8080, 8081 free on the host

---

## Setup

### 1. Generate SSH keys

```bash
bash scripts/gen_ssh_keys.sh
```

This creates `ssh_keys/id_archive` (private) and `ssh_keys/id_archive.pub` (public).

### 2. Create the authorised keys file

```bash
cp ssh_keys/id_archive.pub ssh_keys/authorized_keys
```

### 3. Build and start

```bash
docker compose up --build -d
```

First boot runs the Shimmie2 installer and all DB migrations automatically.

### 4. Open the archive

| URL | Node |
|---|---|
| http://localhost/ | Mirror (public) |
| http://localhost:8081/ | Mirror 2 (public) |
| http://localhost:8080/ | Master (admin) |

---

## Ingest

Navigate to **Multiplatform Ingest** on the master node (`http://localhost:8080`).

| Engine | Use for |
|---|---|
| Auto | Tries gallery-dl → yt-dlp → SingleFile in order |
| gallery-dl | Image boards, Pixiv, Twitter/X, DeviantArt, etc. |
| yt-dlp | YouTube, Vimeo, and other video platforms |
| SingleFile | Any webpage — archived as HTML or PDF |

---

## Integrity Audit (Self-Healing)

Mirror nodes run `integrity_audit.py` via cron every **5 minutes**.

1. Computes SHA-256 for every file in `data/images/`
2. Compares against the hash stored at upload time
3. On mismatch: calls `rsync_pull.sh` to fetch the clean copy from master
4. On success: logs an `INTEGRITY VERIFIED` heartbeat

Results are visible at `http://localhost/network/audit`.

To manually test corruption detection:

```bash
# Find a file hash
docker exec minibooru-mirror sqlite3 /var/www/shimmie/data/shimmie.sqlite \
  "SELECT hash FROM images WHERE sha256_hash IS NOT NULL LIMIT 1;"

# Corrupt the file
HASH=<hash>
docker exec minibooru-mirror bash -c \
  "dd if=/dev/zero of=/var/www/shimmie/data/images/\${HASH:0:2}/\$HASH bs=64 count=1 conv=notrunc"

# Trigger audit immediately
docker exec minibooru-mirror python3 /opt/scripts/integrity_audit.py
```

---

## Network Testing

iperf3 and tcpdump are installed on all nodes.

```bash
# Throughput test
docker exec minibooru-mirror iperf3 -s -D
docker exec minibooru-master iperf3 -c mirror-node -t 30

# Capture RSYNC traffic for Wireshark
docker exec minibooru-master tcpdump -i eth0 -w /tmp/rsync.pcap port 22
docker cp minibooru-master:/tmp/rsync.pcap ./rsync.pcap
```

---

## Custom Extensions

| Extension | Purpose |
|---|---|
| `gallerydl_ingest` | Multiplatform ingest UI (gallery-dl, yt-dlp, SingleFile) |
| `handle_html` | Display SingleFile HTML archives in a sandboxed iframe |
| `network_ops` | RSYNC dashboard, live mirror probes, audit log viewer |
| `sha256_check` | SHA-256 integrity check UI |
| `mass_remover` | Bulk post deletion |
| `perm_manager` | Permission management UI |

---

## Directory Structure

```
.
├── Dockerfile.master        # Master node image
├── Dockerfile.mirror        # Mirror node image
├── docker-compose.yml       # 3-node topology
├── entrypoint.master.sh     # Master startup script
├── entrypoint.mirror.sh     # Mirror startup script
├── ext/                     # Shimmie2 extensions
│   ├── gallerydl_ingest/    # Multiplatform ingest
│   ├── handle_html/         # HTML archive handler
│   ├── network_ops/         # Network dashboard
│   └── ...
├── scripts/
│   ├── rsync_push.sh        # Master → mirror replication
│   ├── rsync_pull.sh        # Self-healing pull from master
│   └── integrity_audit.py   # SHA-256 audit script
└── themes/modernbooru/      # Custom theme
```

---

## License

Shimmie2 core is released under the [GNU GPL v2](LICENSE.txt).
Custom extensions and configuration are original work developed for this FYP.
