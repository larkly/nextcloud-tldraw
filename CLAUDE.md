# Nextcloud tldraw App

This repository contains a **Nextcloud app** that integrates [tldraw](https://tldraw.dev) as a native file editor, enabling real-time collaborative drawing on `.tldr` files.

## Features
- **Real-time Collaboration:** Multiple users can edit the same drawing simultaneously.
- **Native Integration:** `.tldr` files appear in Nextcloud Files with custom icons and previews.
- **Secure Architecture:** Uses short-lived JWTs and authenticated uploads.
- **Dockerized Backend:** Includes a production-ready `docker-compose` setup with Traefik support.

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
    -   Persists room state to SQLite (in-memory/temporary) and flushes to Nextcloud WebDAV.
    -   Handles authenticated asset uploads.

## Installation & Deployment

### 1. Build the Frontend
From the repository root:
```bash
npm install
npm run build
# This generates js/tldraw-main.js
```

### 2. Deploy the Collab Server
Navigate to the root directory (where `docker-compose.yml` is):
1.  Copy `.env.example` to `.env`.
2.  Edit `.env` and set:
    -   `JWT_SECRET_KEY`: A long random hex string (32 bytes recommended).
    -   `NC_URL`: Your Nextcloud instance URL (e.g., `https://cloud.example.com`).
    -   `NC_ADMIN_USER` / `PASS`: Credentials for a **dedicated Service User** (Admin Group) to access files.
    -   `TLDRAW_HOST`: The domain for the collab server (e.g., `tldraw.example.com`).
3.  Start the service:
    ```bash
    docker-compose up -d
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
