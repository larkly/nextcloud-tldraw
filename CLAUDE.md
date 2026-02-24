# Nextcloud tldraw App

This repository contains a **Nextcloud app** that integrates [tldraw](https://tldraw.dev) as a native file editor, enabling real-time collaborative drawing on `.tldr` files.

## Features
- **Real-time Collaboration:** Multiple users can edit the same drawing simultaneously.
- **Native Integration:** `.tldr` files appear in Nextcloud Files with custom icons and previews.
- **Secure Architecture:** Uses short-lived JWTs and authenticated uploads.
- **Dockerized Backend:** Includes a production-ready `docker compose` setup with Traefik support.

## Architecture

### Components
1.  **Nextcloud App (PHP):**
    -   Registers `.tldr` file type and "New Drawing" template.
    -   Serves the React editor.
    -   Issues short-lived (60s) JWTs for WebSocket authentication.
2.  **Frontend (React + Vite):**
    -   Embedded in Nextcloud as a single script (`js/tldraw-main.js`).
    -   Uses `@tldraw/sync` to connect to the Collab Server.
3.  **Collab Server (Node.js):**
    -   Manages WebSocket rooms using `@tldraw/sync-core`.
    -   Persists room state to SQLite (in-memory) and flushes to Nextcloud via PHP callbacks.
    -   Handles authenticated asset uploads (forwarded to Nextcloud, served by PHP).

## Installation & Deployment

### 1. Build the Frontend
From the repository root:
```bash
npm install
npm run build
# This generates js/tldraw-main.js
```

### 2. Deploy the Collab Server
From the repository root:
1.  Copy `.env.example` to `.env`.
2.  Edit `.env` and set:
    -   `JWT_SECRET_KEY`: Generate with `openssl rand -hex 32`.
    -   `NC_URL`: Your Nextcloud instance URL (e.g., `https://cloud.example.com`).
    -   `TLDRAW_HOST`: The domain for the collab server (e.g., `tldraw.example.com`).
3.  Pull and start:
    ```bash
    docker compose pull && docker compose up -d
    ```

### 3. Install the Nextcloud App
1.  Symlink or copy this directory into your Nextcloud `apps/` folder:
    ```bash
    ln -s /path/to/nextcloud-tldraw /var/www/nextcloud/apps/tldraw
    ```
2.  Enable the app:
    ```bash
    php occ app:enable tldraw
    ```
3.  Configure Settings:
    -   Go to **Administration Settings > tldraw**.
    -   **Collab Server URL:** Enter your Traefik host (e.g., `https://tldraw.example.com`).
    -   **JWT Secret:** Enter the *same* secret key from your `.env` file.

## Security Notes
-   **Tokens:** The app uses short-lived JWTs (60s expiry) to authenticate WebSocket connections.
-   **Uploads:** All asset uploads require a valid JWT in the `Authorization` header.
-   **Permissions:** Write access is enforced by the server based on the JWT `canWrite` claim.

## Development
-   **Frontend:** `npm run dev` (requires valid Nextcloud setup to serve the frame).
-   **Backend:** `cd collab-server && npm run dev`.
-   **Collab server build check:** `cd collab-server && npm install && npm run build` (must pass cleanly before any PR).

## Key Technical Notes

-   **`@types/node` must be v22+** — `node:sqlite` (used in `nc-storage.ts`) requires Node.js 22 types.
-   **No Nextcloud credentials on the Collab Server** — file I/O uses PHP callbacks authenticated by a file-scoped `storageToken` (8h HS256 JWT). The storage token is embedded in the WebSocket JWT payload and threaded through the room manager to storage functions.
-   **Asset URL format:** `${NC_URL}/apps/tldraw/asset/${encodeURIComponent(assetKey)}` — assets are served directly by the Nextcloud PHP app, not proxied by the Collab Server.
-   **Filename sanitization:** Any user-supplied filename stored in Nextcloud must be stripped to `[a-zA-Z0-9._-]` to prevent path traversal.
-   **SVG uploads are intentionally rejected** — `image/svg+xml` is not in `ALLOWED_MIMES`; removing this restriction requires adding a server-side XML sanitiser first.
-   **Token URL is injected server-side** via `IURLGenerator::linkToRoute()` in `TldrawController::edit()` and read from `data-token-url` in `main.tsx` — do not revert to a hardcoded `/apps/tldraw/token/` path (breaks subpath installs).
-   **WebSocket JWT expiry is 60 seconds** — tokens are used only for the initial WebSocket handshake; the connection persists after the token expires. Storage tokens expire after 8 hours.

## Workflow Preferences

-   **No `Co-Authored-By` lines** in commit messages.
-   **No AI/Claude attribution** in commit messages, PR bodies, issue comments, or any repository content. Do not mention Claude, AI, or Anthropic.
-   **Feature branches + PR** for all non-trivial changes. Branch naming: `fix/<slug>` or `feat/<slug>`.
-   **Run a quality check** (`Task` tool → `pr-reviewer` agent) before merging any PR.
-   **Close fixed GitHub issues** in the PR body with `Closes #N` so they auto-close on merge.

## Open GitHub Issues (as of v0.0.1+)

| # | Title | Status |
|---|-------|--------|
| 1 | Excessive Privilege (Service Account) | Fixed in `feat/wopi-style-callback-api` — WOPI-style PHP callbacks, no admin account |
| 4 | CSWSH — verify Origin check behind Traefik | Open — needs Traefik proxy config investigation |
