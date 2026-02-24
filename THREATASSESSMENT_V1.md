# Threat Assessment v1.1 (Post-Implementation Audit)

**Date:** 2026-02-24
**Target:** Nextcloud tldraw App
**Audited Commit:** `b342a30`
**Previous Version:** v1.0 (2026-02-20)

## Executive Summary

A code audit against v1.0 findings confirms that three previously unmitigated risks have been addressed:
**V2 (DoS)** is now partially mitigated via HTTP rate limiting and Docker resource limits; **V3 (Stored XSS)** is fully mitigated via magic-byte validation and intentional SVG rejection; **V5 (CSWSH)** has the Origin check active (no longer commented out).

The architecture no longer uses a Nextcloud admin service account — file I/O is delegated entirely to PHP callbacks authenticated by scoped storage tokens, eliminating the V1 "God Mode" risk.

Three new low-to-medium findings were identified in this audit and are tracked as GitHub issues #11–#13.

## Vulnerability Matrix

| ID | Risk | Severity | Component | Status |
|----|------|----------|-----------|--------|
| **V1** | Excessive Privilege (Service Account) | ~~CRITICAL~~ | Collab Server | **Fixed** (WOPI-style PHP callbacks, no admin creds) |
| **V2** | Denial of Service (Memory/Connections) | HIGH | Collab Server | **Partially Mitigated** — HTTP rate-limited; WS upgrades unthrottled (#11) |
| **V3** | Stored XSS (Malicious Assets) | MEDIUM | Collab Server | **Mitigated** (magic bytes + SVG blocked) |
| **V4** | JWT Token in URL | LOW | Frontend/WS | **Mitigated** (60s expiry) |
| **V5** | Cross-Site WebSocket Hijacking | LOW | WebSocket | **Partially Mitigated** — Origin check active; Traefik pass-through unverified (#4) |
| **V6** | Read-Only Bypass | ~~HIGH~~ | WebSocket | **Fixed** (server-side proxy filter) |
| **V7** | Permissive CORS on HTTP Endpoints | LOW | Collab Server | **Open** (#13) |
| **V8** | Traefik Dashboard Exposed | LOW | Infrastructure | **Open** (#12) |

## Detailed Analysis

### V1: The "God Mode" Service Account — FIXED

*   **Previous Status:** Mitigated via operational config (dedicated `tldraw-bot` user).
*   **Current Status:** **Fully Fixed.** The WOPI-style callback API (`feat/wopi-style-callback-api`) eliminates all Nextcloud credentials from the collab server. File I/O is delegated to PHP endpoints authenticated by a scoped storage token (8-hour HS256 JWT, claims: `fileId`, `ownerId`, `filePath`, `canWrite`). The collab server holds no Nextcloud password or session.
*   **Closes:** GitHub Issue #1.

### V2: Denial of Service (DoS) — Partially Mitigated

*   **Description:** Exhaustion of server resources via connection flood or large upload spam.
*   **Mitigations Applied:**
    *   `express-rate-limit`: 1 000 requests per 15-minute window (global, `server.ts` line 24–31).
    *   Docker `mem_limit: 512m` and `cpus: 1.0` in `docker-compose.yml`.
    *   Multer `limits.fileSize: 50 MB` for uploads (`server.ts` line 34).
*   **Remaining Gap:** The `express-rate-limit` middleware applies only to Express routes. WebSocket upgrade requests are handled by the raw `http.Server` `upgrade` event and **bypass Express middleware entirely**. An attacker with a valid 60-second JWT can open an unbounded number of WebSocket connections.
*   **Remaining Work:** Implement per-IP WebSocket connection counting in the `upgrade` handler. Tracked as **GitHub Issue #11**.

### V3: Stored XSS via Assets — Mitigated

*   **Previous Status:** Partially mitigated (NC CSP only).
*   **Current Status:** **Fully Mitigated.**
    *   Magic-byte validation enforced for all four accepted types (PNG `89 50 4E 47`, JPEG `FF D8 FF`, GIF `47 49 46 38`, WebP RIFF header) — `server.ts` lines 63–79.
    *   `ALLOWED_MIMES` whitelist: `['image/jpeg', 'image/png', 'image/gif', 'image/webp']`.
    *   `image/svg+xml` intentionally excluded: SVG is text-based, bypasses magic-byte checks, and requires a full XML sanitizer before it can be safe. This exclusion is documented in `CLAUDE.md` and enforced in both `server.ts` and `FileController.php`.
    *   PHP `FileController::uploadAsset()` applies the same MIME whitelist as a second layer.

### V4: JWT Token in URL — Mitigated

*   **Status:** Unchanged. WebSocket JWT has a 60-second expiry, making replay attacks from server logs impractical. The storage token (embedded in the WebSocket JWT payload) has an 8-hour lifetime but is never directly in the URL.

### V5: Cross-Site WebSocket Hijacking (CSWSH) — Partially Mitigated

*   **Previous Status:** Origin check existed but was commented out.
*   **Current Status:** **Active and enforced** (`server.ts` lines 172–179):
    ```typescript
    const origin = req.headers.origin;
    if (NC_URL && origin && origin !== new URL(NC_URL).origin) {
        socket.write('HTTP/1.1 403 Forbidden (Origin)\r\n\r\n');
        socket.destroy();
        return;
    }
    ```
*   **Remaining Gap:** The check is conditional on `NC_URL` being set; if `NC_URL` is empty the check is skipped. Whether Traefik forwards the `Origin` header unmodified in production has not been verified.
*   **Remaining Work:** Traefik proxy header verification. Tracked as **GitHub Issue #4**.

### V6: Read-Only Enforcement — Fixed

*   **Status:** Unchanged from v1.0. The server wraps read-only WebSocket sessions in a `Proxy` that silently drops any message where `type === 'update'` (`server.ts` lines 124–153). The `canWrite` claim is derived from the signed JWT and cannot be forged by the client.

### V7: Permissive CORS on HTTP Endpoints (New) — Open

*   **Description:** `app.use(cors())` with no configuration allows any origin to make cross-origin HTTP requests to the collab server (e.g., `/upload`).
*   **Severity:** LOW — JWT validation on `/upload` prevents direct exploitation, but the lack of origin restriction on HTTP routes is inconsistent with the strict origin enforcement on the WebSocket path.
*   **Remaining Work:** Restrict `cors()` to `new URL(NC_URL).origin`. Tracked as **GitHub Issue #13**.

### V8: Traefik Dashboard Exposed Without Auth (New) — Open

*   **Description:** `docker-compose.yml` enables `--api.insecure=true` and publishes port `8080`, giving unauthenticated read access to routing rules and TLS metadata.
*   **Severity:** LOW — information disclosure risk; aids attacker reconnaissance.
*   **Remaining Work:** Remove `--api.insecure` and the `8080` port mapping for production, or protect the dashboard with BasicAuth. Tracked as **GitHub Issue #12**.

## Conclusion

The application is in a significantly stronger security posture than at v1.0:

*   **Critical risk (V1)** has been fully eliminated — no Nextcloud credentials on the collab server.
*   **High risk (V2)** is substantially reduced — HTTP endpoints are rate-limited and the container has hard resource limits. The remaining gap (WebSocket upgrade rate limiting, #11) should be addressed before public-facing deployment.
*   **Medium risk (V3)** is fully mitigated — magic bytes enforced, SVG rejected.
*   **Low risks (V4, V6)** are fixed.
*   **Remaining open items:** V2 WebSocket rate limiting (#11), V5 Traefik Origin verification (#4), V7 CORS restriction (#13), V8 Traefik dashboard (#12).

The application is safe for **trusted internal environments**. For public-facing deployments, resolving **#11 (WebSocket DoS)** is strongly recommended before go-live.
