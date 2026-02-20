# System Architecture

This document describes the high-level architecture of the Nextcloud tldraw application.

## Overview

The system bridges a traditional LAMP stack application (Nextcloud) with a modern real-time WebSocket service (Node.js). Security and state synchronization are handled via short-lived JWTs and WebDAV.

## Components

### 1. Nextcloud App (PHP)

-   **Role:** Authentication Provider & File Manager.
-   **Responsibilities:**
    -   Authenticates the user via the Nextcloud session.
    -   Checks file permissions (Read / Write / Share).
    -   Issues a signed **JWT** containing:
        -   `fileId` — the Nextcloud file ID.
        -   `roomToken` — HMAC-SHA256 derived from `fileId` (deterministic but obscure).
        -   `userId` — the current user's ID.
        -   `ownerId` — the file owner's ID (used by the collab server to determine the WebDAV path).
        -   `filePath` — path relative to the owner's home folder.
        -   `canWrite` — permission flag.
        -   `exp` — expiration time (60 seconds from issue).
    -   Generates the token endpoint URL server-side via `IURLGenerator::linkToRoute()` and injects it into the editor template as a `data-token-url` attribute. This ensures the correct URL is used regardless of Nextcloud's subpath configuration.
    -   Serves the frontend assets.

### 2. Frontend (React/Vite)

-   **Role:** The User Interface.
-   **Responsibilities:**
    -   Embedded as a single script (`js/tldraw-main.js`) in the PHP template.
    -   Reads the token URL from the `data-token-url` attribute on the root element (injected by PHP — never hardcoded).
    -   Fetches the short-lived JWT from the token endpoint.
    -   Connects to the Collab Server via WebSocket using the JWT.
    -   Handles asset uploads to the Collab Server (`POST /uploads`).

### 3. Collab Server (Node.js / Docker)

-   **Role:** Real-time Sync Engine.
-   **Container image:** `ghcr.io/larkly/nextcloud-tldraw` (published to GitHub Container Registry on every release).
-   **Responsibilities:**
    -   **WebSocket Server:** Uses `@tldraw/sync-core` to manage collaborative rooms.
    -   **Room Management:** Each open file gets an in-memory SQLite room; rooms are keyed by `roomToken`.
    -   **Persistence:**
        -   **Load:** On room creation, fetches the `.tldr` file from Nextcloud via WebDAV (using the Service User credentials).
        -   **Flush:** Every 30 seconds and on room close, serializes the room state to JSON and `PUT`s it back to Nextcloud via WebDAV.
    -   **Asset Storage:** Uploaded images are stored in `.tldraw-assets/` in the file owner's Nextcloud home directory and served back via the collab server at `/uploads/<userId>/<filename>`.
    -   **Security:**
        -   Validates JWT signature and expiry on every WebSocket connection.
        -   Enforces the `canWrite` claim — read-only clients have write messages silently dropped.
        -   Validates the `Origin` header against `NC_URL`.
        -   Sanitizes uploaded filenames to `[a-zA-Z0-9._-]` before WebDAV path insertion.
        -   Rejects SVG uploads (text-based format; not safe without a server-side XML sanitiser).

## Data Flow

### 1. User Opens File
```
Browser → GET /apps/tldraw/edit/{fileId}
        ← PHP checks permissions, renders editor template with data-token-url injected
```

### 2. Editor Initialization
```
React   → GET {tokenUrl}   (e.g. /apps/tldraw/token/42)
        ← PHP issues 60s JWT, returns {token, wsUrl}
React   → WebSocket upgrade: wss://tldraw.example.com/connect?token={JWT}
        ← Collab Server validates JWT, opens room
```

### 3. Real-Time Editing
```
User draws shape
  → tldraw encodes as binary update
  → WebSocket message to Collab Server
  → Collab Server broadcasts to all other clients in the room
  → Other clients render the update
```

### 4. Persistence (Auto-Save)
```
Every 30s (or on last client disconnect):
  Collab Server serializes room state to JSON snapshot
  → PUT to Nextcloud WebDAV as Service User
     (path: /remote.php/dav/files/{ownerId}/{filePath})
```

## Security Considerations

-   **JWT Expiry (60s):** Tokens are single-use for the WebSocket handshake only. The connection itself remains open; the token is not re-checked after the handshake.
-   **Service User:** The Collab Server uses a dedicated Nextcloud admin account (`tldraw-bot`) instead of a global credential, limiting blast radius.
-   **Asset Isolation:** Assets are stored per-user in `.tldraw-assets/` and are served proxied through the collab server — not exposed directly from Nextcloud.
-   **Resource Limits:** The Docker container is capped at 512 MB RAM and 1 CPU core to prevent DoS via resource exhaustion.
