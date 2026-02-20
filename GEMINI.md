# Project Context: Nextcloud tldraw

## Overview
This project is a **Nextcloud app** that integrates [tldraw](https://tldraw.dev) (v4.4.0) for real-time collaborative whiteboarding. It allows users to create and edit `.tldr` files directly within Nextcloud, with synchronization handled by a separate Node.js server.

## Architecture

### 1. Nextcloud App (PHP)
*   **Location:** Root directory (`lib/`, `appinfo/`, `templates/`).
*   **Framework:** Nextcloud App Framework (PHP 8.2+).
*   **Responsibilities:**
    *   **File Integration:** Registers `.tldr` MIME type and "New Drawing" menu item.
    *   **Authentication:** Verifies Nextcloud session and file permissions.
    *   **Token Issuance:** `TldrawController::token` generates a **JWT** (signed with a shared secret) containing:
        *   `fileId`: The file being edited.
        *   `ownerId`: The User ID of the file owner (crucial for shared editing).
        *   `filePath`: The path to the file relative to the owner's root.
        *   `canWrite`: Boolean permission flag.
        *   `exp`: Expiration (12 hours).
    *   **Frontend Bootstrapping:** `templates/editor.php` injects the React bundle.

### 2. Frontend (React + Vite)
*   **Location:** `src/`.
*   **Build:** Compiles to a single IIFE bundle at `js/tldraw-main.js`.
*   **Dependencies:** `tldraw`, `@tldraw/sync`, `@tldraw/assets`.
*   **Key Logic:**
    *   `src/TldrawEditor.tsx`: Initializes the editor and connects to the WebSocket. Handles Read-Only mode rendering.
    *   `src/asset-store.ts`: Custom `TLAssetStore`. Uploads to the Collab Server and resolves relative URLs (e.g., `/uploads/...`) to absolute URLs.

### 3. Collab Server (Node.js)
*   **Location:** `collab-server/`.
*   **Runtime:** Node.js 22 (Dockerized).
*   **Dependencies:** `@tldraw/sync-core` (uses experimental SQLite), `express`, `ws`, `webdav`.
*   **Responsibilities:**
    *   **WebSocket Sync:** Manages rooms and broadcasts updates.
    *   **Persistence:** Loads/Saves document state from/to Nextcloud via WebDAV using a **Service User**.
    *   **Asset Proxy:** Handles authenticated uploads (`POST /uploads`) and serves assets (`GET /uploads/:userId/:filename`).
*   **Security Mechanisms:**
    *   **Read-Only Enforcement:** Wraps WebSockets for read-only users to silently drop "update" messages.
    *   **Rate Limiting:** `express-rate-limit` enabled.
    *   **Input Validation:** Checks MIME types and Magic Bytes for images. Validates Origin header.

## Data Flow

1.  **Editing:** User opens file -> PHP serves page -> React connects to WS -> Node.js verifies JWT -> Room loaded from WebDAV.
2.  **Saving:** Node.js buffers changes in SQLite (memory) -> Flushes to Nextcloud WebDAV every 30s.
3.  **Shared Files:**
    *   If User B accesses User A's file, PHP generates a token with `ownerId=UserA`.
    *   Node.js uses `UserA`'s WebDAV root to read/write the file, ensuring consistent state.

## Deployment

*   **Distribution:** `nextcloud-tldraw-x.y.z.tar.gz` (contains PHP app + built JS).
*   **Backend:** Docker Container (`docker-compose.yml`).
*   **Configuration:**
    *   `.env`: `NC_URL`, `NC_USER` (Bot), `NC_PASS` (App Password), `JWT_SECRET_KEY`.
    *   Nextcloud Admin Settings: Collab Server URL, JWT Secret.

## Current State (v0.0.1)

*   **Repository:** `larkly/nextcloud-tldraw`
*   **Version:** `0.0.1`
*   **Status:** Production-ready for internal/trusted networks.
*   **Known Risks:** The Service User (`NC_USER`) requires Admin group membership to access other users' files via WebDAV. This is a high-privilege account.
