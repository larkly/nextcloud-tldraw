# System Architecture

This document describes the high-level architecture of the Nextcloud tldraw application.

## Overview

The system bridges a traditional LAMP stack application (Nextcloud) with a modern real-time WebSocket service (Node.js). Security and state synchronization are handled via JWTs and WebDAV.

## Components

### 1. Nextcloud App (PHP)
-   **Role:** Authentication Provider & File Manager.
-   **Responsibilities:**
    -   Authenticates the user via Nextcloud session.
    -   Checks file permissions (Read/Write/Share).
    -   Issues a signed **JWT (JSON Web Token)** containing:
        -   `fileId`: The Nextcloud file ID.
        -   `roomToken`: HMAC-SHA256 derived from `fileId` (deterministic but obscure).
        -   `userId`: The current user's ID.
        -   `canWrite`: Permission flag.
        -   `exp`: Expiration time (60 seconds).
    -   Serves the Frontend assets.

### 2. Frontend (React/Vite)
-   **Role:** The User Interface.
-   **Responsibilities:**
    -   Embedded as a single script (`js/tldraw-main.js`) in the PHP template.
    -   Fetches the JWT from the PHP backend (`/apps/tldraw/token/{fileId}`).
    -   Connects to the Collab Server via WebSocket (`wss://...`).
    -   Handles asset uploads to the Collab Server (`POST /uploads`).

### 3. Collab Server (Node.js)
-   **Role:** Real-time Sync Engine.
-   **Responsibilities:**
    -   **WebSocket Server:** Uses `@tldraw/sync-core`.
    -   **Room Management:** Manages in-memory SQLite databases for active rooms.
    -   **Persistence:**
        -   **Load:** On room creation, fetches the `.tldr` file content from Nextcloud via WebDAV.
        -   **Flush:** Every 30 seconds (and on room close), saves the room state back to Nextcloud via WebDAV.
    -   **Security:**
        -   Validates JWT signature on connection.
        -   Enforces `canWrite` permission on incoming messages.
        -   Validates `Origin` header.

## Data Flow

1.  **User Opens File:**
    -   Browser requests `/apps/tldraw/edit/{fileId}`.
    -   PHP checks permissions and serves the editor page.

2.  **Editor Initialization:**
    -   React app fetches a short-lived JWT from `/apps/tldraw/token/{fileId}`.
    -   React app connects to `wss://collab.example.com/connect/{fileId}?token={JWT}`.

3.  **Real-Time Edit:**
    -   User draws a shape.
    -   Operation is sent to Collab Server.
    -   Collab Server broadcasts to other connected clients in the same room.

4.  **Persistence (Auto-Save):**
    -   Collab Server buffers changes in memory (SQLite).
    -   Every 30 seconds, it serializes the room state to JSON.
    -   It uses the Service User credentials to `PUT` the JSON to Nextcloud WebDAV.

## Security Considerations

-   **JWT Expiry:** Tokens are short-lived (60s) to minimize risk if a URL is logged.
-   **Service User:** The Collab Server uses a dedicated Nextcloud user, limiting the blast radius compared to using an Admin account.
-   **Asset Storage:** Uploaded images are stored in a hidden folder `.tldraw-assets/` in the user's root directory.
