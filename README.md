# Nextcloud tldraw App

> [!CAUTION]
> **Project is archived.** As tldraw is proprietary and requires a license to use in production, it has some unacceptable implications for any Nextcloud instance in production to utilize this. As such, the work has been ended and the repository archived.

> [!WARNING]
> **This project is currently in early development and is not ready for production use.**
> We are actively working on it, and pull requests are accepted and greatly appreciated!

```
  _   _           _       _                 _
 | \ | | _____  _| |_ ___| | ___  _   _  __| |
 |  \| |/ _ \ \/ / __/ __| |/ _ \| | | |/ _` |
 | |\  |  __/>  <| || (__| | (_) | |_| | (_| |
 |_| \_|\___/_/\_\\__\___|_|\___/ \__,_|\__,_|

      _   _     _
     | |_| | __| |_ __ __ ___      __
     | __| |/ _` | '__/ _` \ \ /\ / /
     | |_| | (_| | | | (_| |\ V  V /
      \__|_|\__,_|_|  \__,_| \_/\_/
```

This application integrates [tldraw](https://tldraw.dev) into Nextcloud, allowing users to create, edit, and collaborate on whiteboards in real-time. Drawings are stored natively as `.tldr` files within your Nextcloud instance.

## Features

- **Real-Time Collaboration:** Multiple users can edit the same drawing simultaneously.
- **Native Integration:** `.tldr` files appear in Nextcloud Files with custom icons and previews.
- **Secure Architecture:** Uses short-lived JWTs (60s) and authenticated uploads.
- **Dockerized Backend:** Production-ready `docker compose` setup with Traefik support.

## Quick Start

To deploy this application you need:
1. A Nextcloud 28+ server (PHP 8.2+).
2. A server running Docker and Docker Compose v2, with a dedicated domain (e.g. `tldraw.example.com`).

### 1. Clone the repository

```bash
git clone https://github.com/larkly/nextcloud-tldraw.git
cd nextcloud-tldraw
```

### 2. Deploy the Collab Server (Docker)

The Collab Server is the Node.js backend that handles real-time sync. Its container image is published to the GitHub Container Registry:

```
ghcr.io/larkly/nextcloud-tldraw:latest
```

1. **Create a Service User in Nextcloud:**
   - Create a new user (e.g. `tldraw-bot`) and add them to the `admin` group.
   - Log in as that user, go to **Settings > Security > Devices & sessions**, and generate an **App Password** named `Collab Server`. Copy it — it is shown only once.
   - See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for full details on why admin access is needed.

2. **Configure the environment:**
   ```bash
   cp .env.example .env
   ```
   Edit `.env` and fill in your values:
   - `JWT_SECRET_KEY` — generate with `openssl rand -hex 32`
   - `NC_URL` — your Nextcloud instance URL (no trailing slash)
   - `NC_USER` / `NC_PASS` — the Service User username and App Password from Step 1
   - `TLDRAW_HOST` — the domain for the collab server

3. **Pull and start:**
   ```bash
   docker compose pull
   docker compose up -d
   ```

4. **Verify:** open `https://tldraw.example.com/health` — you should see `{"status":"ok"}`.

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for Traefik configuration, pinning to a specific version, and building from source.

### 3. Install the Nextcloud App

1. Build the release archive:
   ```bash
   bash scripts/make-release.sh
   ```
   This produces `nextcloud-tldraw-<version>.tar.gz`.

2. Extract it into your Nextcloud apps directory:
   ```bash
   tar -xzf nextcloud-tldraw-<version>.tar.gz -C /var/www/nextcloud/apps/
   ```

3. Enable the app:
   ```bash
   php occ app:enable tldraw
   ```

4. In Nextcloud, go to **Administration Settings > tldraw** and set:
   - **Collab Server URL** → `https://tldraw.example.com`
   - **JWT Secret** → the same value as `JWT_SECRET_KEY` in `.env`

## Usage

1. Navigate to the **Files** app in Nextcloud.
2. Click **+ New** and select **New tldraw drawing**.
3. Name your file (e.g. `brainstorming.tldr`) and press Enter.
4. Click the file to open the editor.
5. Share the file with other users to collaborate in real-time.

See [docs/USAGE.md](docs/USAGE.md) for a full user guide.

## Documentation

| Guide | Contents |
|---|---|
| [User Guide](docs/USAGE.md) | Creating drawings, collaboration, exporting, inserting images |
| [Deployment Guide](docs/DEPLOYMENT.md) | Collab Server setup, Traefik, GHCR image tags |
| [Administration Guide](docs/ADMINISTRATION.md) | Updates, configuration reference, troubleshooting |
| [Architecture](docs/ARCHITECTURE.md) | System design, data flow, security model |

## Development

```bash
# Frontend (watches for changes and rebuilds)
npm install
npm run dev

# Collab Server
cd collab-server && npm install && npm run dev

# Backend (PHP): symlink this directory to your local Nextcloud apps/ folder
# and enable debug mode in Nextcloud to disable caching
```

## Security

- **Tokens:** WebSocket connections use short-lived JWTs (60s expiry) for the initial handshake.
- **Uploads:** Asset uploads require a valid JWT in the `Authorization` header.
- **Permissions:** Write access is enforced server-side via the JWT `canWrite` claim.

## Acknowledgements

This project would not be possible without the excellent work of the [tldraw](https://tldraw.dev) team.

- **[tldraw](https://tldraw.dev)** — the whiteboard library powering the editor.
- **[@tldraw/sync](https://github.com/tldraw/tldraw/tree/main/packages/sync)** — the real-time collaboration primitives used by the collab server.

> [!IMPORTANT]
> tldraw is **not** distributed under an OSI-approved open-source license. Its [custom license](https://github.com/tldraw/tldraw/blob/main/LICENSE.md) permits free use in **development environments only**. Production deployment requires a separate commercial license from tldraw, Inc. Review the tldraw license before deploying this app to production.

## License

The code in this repository is licensed under the [GNU Affero General Public License v3.0](LICENSE) (AGPL-3.0).

**Note on third-party dependencies:** The core drawing library, [tldraw](https://tldraw.dev), is distributed under a [proprietary license](https://github.com/tldraw/tldraw/blob/main/LICENSE.md) that is not OSI-approved. Production use of tldraw requires a separate commercial license from tldraw, Inc. The AGPL-3.0 license of this repository does not grant any rights to tldraw's code. See [`docs/LEGAL.md`](docs/LEGAL.md) for a full dependency licence summary and operator obligations.
