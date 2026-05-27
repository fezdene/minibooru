# minibooru — Project Context for Claude Code

## What this is
Distributed Web Archiving System — UiTM Final Year Project.
Frontend: **Shimmie2 2.12.2** (PHP image-board framework), customised into a media archive.
Backend: Master-Mirror replication via RSYNC + SHA-256 integrity auditing.

---

## Security constraint (always enforce)
When writing PHP: compatible with PHP 8.0+, strictly protect against command injection and SQL injection. Use `escapeshellarg()` for all shell arguments, parameterised queries for all DB calls.

---

## Architecture

```
host
├── port 8080 ──► minibooru-master   (admin ingest — restricted)
├── port 80   ──► minibooru-mirror   (primary public archive)
└── port 8081 ──► minibooru-mirror-2 (secondary public archive)
                       │
                 [archive-net bridge]
                       │
                 master-node  ──RSYNC every 30s──► both mirrors
```

- **master-node**: ingests media, runs gallery-dl / yt-dlp / SingleFile, holds SQLite DB, pushes to mirrors.
- **mirror-node / mirror-2**: read-only replicas, SSH server for RSYNC ingress, integrity audit cron every 15 min.
- Database: **SQLite** per node, synced by RSYNC (entire `data/` directory).
- Container names: `minibooru-master`, `minibooru-mirror`, `minibooru-mirror-2`.

---

## Software versions (as installed in running containers)

| Software        | Version          | Where         |
|-----------------|------------------|---------------|
| PHP             | 8.4.21           | master+mirror |
| Apache          | 2.4.67           | master+mirror |
| Python          | 3.13.5           | master+mirror |
| iperf3          | Debian system    | master+mirror |
| tcpdump         | Debian system    | master+mirror |
| gallery-dl      | 1.32.1           | master only   |
| yt-dlp          | 2026.03.17       | master only   |
| SingleFile CLI  | 2.0.83           | master only   |
| Chromium        | 148.0.7778.167   | master only   |
| Node.js         | v20.19.2         | master only   |
| npm             | 9.2.0            | master only   |
| ffmpeg          | 7.1.4            | master only   |
| ImageMagick     | 7.1.1-43         | master only   |
| SQLite3         | Debian system    | master+mirror |

gallery-dl and yt-dlp are installed in a venv at `/opt/gallery-dl-venv/`.
Symlinked to `/usr/local/bin/gallery-dl` and `/usr/local/bin/yt-dlp`.
SingleFile CLI installed globally via npm: `/usr/local/bin/single-file`.
Chromium at: `/usr/bin/chromium`.

---

## PHP configuration (master)

| Setting              | Value |
|----------------------|-------|
| upload_max_filesize  | 64M   |
| post_max_size        | 64M   |
| memory_limit         | 256M  |
| max_execution_time   | 300   |

For long-running ingest operations (SingleFile + Chromium), call `set_time_limit(0)` and `Ctx::$database->set_timeout(null)` before the operation.

---

## Custom extensions

| Extension            | Location                       | Purpose                                          |
|----------------------|--------------------------------|--------------------------------------------------|
| `gallerydl_ingest`   | `ext/gallerydl_ingest/`        | Multiplatform Ingest — gallery-dl, yt-dlp, SingleFile |
| `handle_html`        | `ext/handle_html/`             | Store and display SingleFile HTML archives       |
| `mass_remover`       | `ext/mass_remover/`            | Bulk post deletion                               |
| `network_ops`        | `ext/network_ops/`             | RSYNC push dashboard                             |
| `sha256_check`       | `ext/sha256_check/`            | SHA-256 integrity verification                   |
| `perm_manager`       | `ext/perm_manager/`            | Permission management UI                         |

Extensions are enabled via `data/config/extensions.conf.php` (written by DB migration v24).

---

## Custom theme

Theme: **modernbooru** — located at `themes/modernbooru/`.

| File                  | Purpose                                              |
|-----------------------|------------------------------------------------------|
| `view.theme.php`      | Post view page — adds download card to left sidebar  |

The download card shows: color-coded file-type badge + filename + size/dims (info row), then a full-width "Download this file" button below.

---

## DB migration versioning

Global Shimmie2 migrations: `ext/upgrade/main.php` — currently at **v25**.
- v24: applies minibooru defaults (theme, extensions list, nice_urls, tags_min)
- v25: enables `handle_html` extension

Per-extension migrations use `get_version()`/`set_version()` inside each extension's `onDatabaseUpgrade()`.

`gallerydl_ingest` extension: currently at **v3** (v2 = ingest_format column, v3 = ingest_engine column).

Both `entrypoint.master.sh` and `entrypoint.mirror.sh` run `php index.php db-upgrade` on every boot (idempotent).

---

## Bind mounts — live code editing

All 3 nodes mount `./:/var/www/shimmie` (master: read-write; mirrors: `:ro`).
VS Code edits are immediately live in all containers — no rebuild required.
Each node overlays its own data directory on top (`./master_data`, `./mirror_data`, `./mirror_2_data`).

Hot-patching is only needed for OPcache invalidation after editing PHP:
```bash
docker exec minibooru-master php -r "opcache_reset(); echo 'ok';"
```

## Network testing (Chapter 3.6)

iperf3 and tcpdump are installed on all nodes.

```bash
# Throughput test — run server on mirror, client on master:
docker exec minibooru-mirror iperf3 -s -D
docker exec minibooru-master iperf3 -c mirror-node -t 30

# Capture RSYNC traffic on master:
docker exec minibooru-master tcpdump -i eth0 -w /tmp/rsync.pcap port 22

# Copy pcap to host for Wireshark:
docker cp minibooru-master:/tmp/rsync.pcap ./rsync.pcap
```

---

## Ingest engine details

The `gallerydl_ingest` extension supports three engines selected per-request:

| Engine      | Handles                                      | Fallback order |
|-------------|----------------------------------------------|----------------|
| gallery-dl  | Images from supported platforms (pixiv, etc) | 1st (auto)     |
| yt-dlp      | Video platforms (YouTube, etc)               | 2nd (auto)     |
| SingleFile  | Any webpage → HTML or PDF archive            | 3rd (auto)     |

Auto mode cascades gallery-dl → yt-dlp → SingleFile.
Format toggle (HTML / PDF) only applies when SingleFile engine is selected.
HTML archives are handled by the `handle_html` extension (sandboxed iframe).
PDF archives are handled by Shimmie2's built-in `handle_pdf` extension.

Temp ingest directory: `/tmp/ingest/`

---

## Key file locations inside containers

| Path                                      | Content                        |
|-------------------------------------------|--------------------------------|
| `/var/www/shimmie/`                       | Shimmie2 application root      |
| `/var/www/shimmie/data/shimmie.sqlite`    | SQLite database                |
| `/var/www/shimmie/data/images/`           | Uploaded media files           |
| `/var/www/shimmie/data/thumbs/`           | Generated thumbnails           |
| `/var/www/shimmie/data/config/`           | Runtime config + extensions    |
| `/opt/scripts/`                           | RSYNC push scripts             |
| `/var/log/rsync_audit.log`                | Integrity audit log            |
| `/root/.ssh/id_archive`                   | SSH private key (master)       |
| `/root/.ssh/authorized_keys`              | SSH public key (mirrors)       |
