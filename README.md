# 🎵 Purple Music

A lightweight, self-hosted web app to stream and organize your personal music library — dark purple UI, synced lyrics, per-user themes, multi-language, and a REST API compatible with the companion Android app. Runs on plain PHP + SQLite, no heavy dependencies.

![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php)
![SQLite](https://img.shields.io/badge/Database-SQLite-003B57?style=flat-square&logo=sqlite)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED?style=flat-square&logo=docker)
![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)

## Quick start (Docker)

```bash
docker run -d \
  --name purplemusic \
  -p 51837:80 \
  -v $(pwd)/purplemusic-data:/var/www/purplemusic-data \
  -v $(pwd)/purplemusic-music:/var/www/html/music \
  -v $(pwd)/purplemusic-covers:/var/www/html/covers \
  ghcr.io/axolat000/purplemusic:latest
```

Open `http://localhost:51837` — the setup wizard (site name, admin account, theme) runs automatically on first visit. That's it.

Three folders show up next to where you ran the command (`purplemusic-data`, `purplemusic-music`, `purplemusic-covers`) — that's where everything lives, easy to back up or move. The database is stored **outside** the web-served directory, so it's never reachable over HTTP, no matter how the container is configured.

Prefer `docker compose`? See [`DOCKER.md`](DOCKER.md) for that and more (backups, updating, publishing your own image).

## Features

- **Smart & responsive UI** — modern dark-purple design, sections for recent adds / most played / your mixes, full-screen player on mobile.
- **Synced lyrics** — fetched automatically from [lrclib.net](https://lrclib.net) and cached, line-by-line highlighting as the track plays.
- **Per-user themes** — 5 presets (Violet, Amoled, Midnight, Forest, Crimson), on top of the admin's site-wide default palette.
- **Multi-language** — English, French, Spanish, German.
- **Multi-format support** — plays and auto-detects duration for `.mp3`, `.wav`, `.flac`, and `.ogg`; ID3v2 tags (title/artist/embedded artwork) extracted automatically on upload.
- **Playlists ("Mixes")** — create, edit, and share; opening one shows the track list first, no surprise auto-play.
- **REST API** — the same API the companion Android app talks to (streaming, playlists, uploads, auth).
- **Security** — prepared statements, CSRF protection, login rate-limiting, upload MIME-sniffing, database stored outside the web root.

## Manual installation (no Docker)

If you'd rather run it directly on a web server you already manage:

1. **Requirements**: a web server (Apache/Nginx/LiteSpeed), PHP 7.4+, with `pdo_sqlite` (required) and `gd` (optional, for cover image optimization).
2. Clone the repo into your web directory:
   ```bash
   git clone https://github.com/Axolat000/PurpleMusic.git
   cd PurpleMusic
   ```
3. Point your web server's document root at that folder and visit it in a browser — the setup wizard handles the rest.
4. **Recommended**: make sure your web server actually applies `.htaccess` rules (Apache) or add an equivalent rule (Nginx/other) to block direct access to `config.php` and any `.db`/`.sqlite` files that end up in the app's root — the Docker image already does this by storing the database outside the served directory entirely.

## Updating

```bash
# Docker
docker pull ghcr.io/axolat000/purplemusic:latest
docker stop purplemusic && docker rm purplemusic
# then re-run the docker run command above

# Manual
git pull
```

Your data (accounts, tracks, playlists) is untouched either way.

Admins also get an in-app notification when a new version is published. With `docker compose` and an optional
Watchtower sidecar enabled (see [`DOCKER.md`](DOCKER.md)), that notification comes with a one-click "Update now"
button — the app never gets direct Docker socket access itself, only the dedicated sidecar does. Without it
(or with the plain `docker run` setup above), the same notification shows the manual commands instead.

## License

MIT — see [LICENSE.md](LICENSE.md).
