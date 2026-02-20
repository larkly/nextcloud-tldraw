# System Architecture

This document describes the design of the Nextcloud tldraw application for contributors and anyone wanting to understand how the pieces fit together.

## Overview

The system connects Nextcloud (PHP) with a real-time Node.js WebSocket service. Nextcloud handles authentication and file storage; the Collab Server handles real-time sync. The two communicate via short-lived JWTs (WebSocket auth) and file-scoped storage tokens (file I/O callbacks). The Collab Server holds no Nextcloud credentials.

```
┌─────────────────────────────────┐         ┌──────────────────────────────┐
│         Nextcloud (PHP)         │         │    Collab Server (Node.js)   │
│                                 │  60s JWT │                              │
│  • User auth & file permissions │─────────►│  • WebSocket rooms           │
│  • Issues JWTs + storage tokens │         │  • Real-time sync            │
│  • Exposes file I/O callbacks   │◄─────────│  • Calls back for file I/O   │
│  • Stores .tldr files & assets  │callbacks │  • No Nextcloud credentials  │
└─────────────────────────────────┘         └──────────────────────────────┘
```

---

## Components

### 1. Nextcloud App (PHP)

**Role:** Authentication provider and file manager.

- Authenticates users via the existing Nextcloud session — no separate login.
- Checks per-file read/write permissions using the Nextcloud Files API.
- Issues a signed JWT for each file open request (see [JWT contents](#jwt-contents) below).
- Generates the token endpoint URL server-side via `IURLGenerator::linkToRoute()` and injects it into the editor template as a `data-token-url` attribute, ensuring correct URLs regardless of whether Nextcloud is installed at a subpath.
- Serves the compiled React frontend as a single script (`js/tldraw-main.js`).

#### WebSocket JWT claims

Issued by `TldrawController::token()`, short-lived (60 seconds — used only for the initial WebSocket handshake).

| Claim | Description |
|---|---|
| `fileId` | Nextcloud file ID |
| `roomToken` | HMAC-SHA256 of `"room:" + fileId`, keyed by the JWT secret — deterministic per file but not guessable |
| `userId` | Current user's Nextcloud ID |
| `canWrite` | Boolean — whether the current user has write permission |
| `storageToken` | Embedded storage token (see below) — passed to the Collab Server for file I/O callbacks |
| `exp` | Unix timestamp 60 seconds from issue |

#### Storage token claims

Embedded inside the WebSocket JWT as the `storageToken` claim. Used by the Collab Server for all file I/O callbacks; longer-lived (8 hours — covers a full editing session).

| Claim | Description |
|---|---|
| `type` | Always `"storage"` — prevents use as a WebSocket token |
| `fileId` | Nextcloud file ID |
| `ownerId` | File owner's Nextcloud ID — used by PHP to open the correct user folder |
| `filePath` | Path of the file relative to the owner's home folder |
| `canWrite` | Boolean — enforced by PHP on PUT/POST callbacks |
| `exp` | Unix timestamp 8 hours from issue |

### 2. Frontend (React + Vite)

**Role:** The drawing editor UI.

- Bundled as a single script embedded in the PHP template.
- On load, reads the token URL from `data-token-url` (injected by PHP) and fetches a JWT.
- Opens a WebSocket connection to the Collab Server using the JWT as a query parameter.
- Sends drawing operations to the Collab Server and renders updates from other users in real-time.
- Uploads image assets to the Collab Server (`POST /uploads`), which stores them in Nextcloud.

### 3. Collab Server (Node.js / Docker)

**Role:** Real-time sync engine.

**Container image:** `ghcr.io/larkly/nextcloud-tldraw` ([GHCR](https://github.com/larkly/nextcloud-tldraw/pkgs/container/nextcloud-tldraw))

- **WebSocket rooms:** Each open `.tldr` file gets a room keyed by its `roomToken`. Rooms are created on first connection and cleaned up after the last client disconnects.
- **In-memory state:** Active room state is held in a per-room SQLite database (in-memory) for fast read/write during collaboration.
- **Persistence:** Room state is serialized to JSON and written back to Nextcloud via PHP callback (`PUT /apps/tldraw/file/{fileId}`, authenticated with the storage token) every 30 seconds and when the last client disconnects. On room creation, the current file content is loaded via `GET /apps/tldraw/file/{fileId}` to seed the room.
- **Asset storage:** Uploaded images are forwarded to Nextcloud via `POST /apps/tldraw/file/{fileId}/asset`. Assets are served directly by Nextcloud at `/apps/tldraw/asset/{key}` — the Collab Server does not proxy asset traffic.

---

## Data Flow

### Opening a file

```
1. Browser   →  GET /apps/tldraw/edit/{fileId}
               PHP checks file permission, renders editor HTML with data-token-url injected

2. React     →  GET {tokenUrl}                   (e.g. /apps/tldraw/token/42)
               PHP verifies session, issues 60s JWT
               Returns: { token, wsUrl }

3. React     →  WebSocket upgrade to wss://tldraw.example.com?token={JWT}
               Collab Server validates JWT signature + expiry
               Loads room (fetches file from Nextcloud if not already in memory)
               Connection established
```

### Real-time editing

```
4. User draws a shape
   React encodes the operation as a binary @tldraw/sync update
   →  WebSocket message to Collab Server
   Collab Server applies it to the room's in-memory SQLite state
   →  Broadcasts the update to all other connected clients
   Other clients render the update instantly
```

### Auto-save

```
5. Every 30 seconds (and when the last client disconnects):
   Collab Server serializes the room's SQLite state to a JSON snapshot
   →  PUT /apps/tldraw/file/{fileId}
      Authorization: Bearer <storageToken>
   PHP validates the storage token, writes file via IRootFolder
   The .tldr file in Nextcloud is updated
```

---

## Security Model

| Concern | Mitigation |
|---|---|
| Unauthorized WebSocket access | JWT required; signature verified with shared secret; 60s expiry limits replay window |
| Read-only enforcement | `canWrite: false` in JWT causes Collab Server to silently drop write messages from that client |
| Cross-site WebSocket hijacking | `Origin` header validated against `NC_URL` on every upgrade request |
| Path traversal via asset upload | Uploaded filenames are stripped to `[a-zA-Z0-9._-]` before being used in WebDAV paths |
| Malicious file uploads | Magic bytes validated against declared MIME type; SVG rejected entirely (requires XML sanitiser to be safe) |
| DoS via resource exhaustion | Docker container capped at 512 MB RAM and 1 CPU core; rate limiting on HTTP endpoints (1000 req / 15 min) |
| Compromised Collab Server | Storage tokens are scoped to a single file and expire after 8 hours — a compromised container cannot access files that are not currently open |
| Credential exposure | Collab Server holds no Nextcloud credentials — only `JWT_SECRET_KEY`. Rotating the secret in Admin Settings immediately invalidates all active tokens |
| Privilege escalation | PHP file callbacks enforce `canWrite` from the token; read-only users cannot trigger a write even if they forge a request to the collab server |
