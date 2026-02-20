# Threat Assessment v1.0 (Post-Implementation)

**Date:** 2026-02-20
**Target:** Nextcloud tldraw App
**Status:** Implemented

## Executive Summary

The current implementation has significantly improved security posture compared to the initial design. The adoption of **short-lived JWTs (60s)**, **Header-based authentication for uploads**, and **Server-side Read-Only enforcement** mitigates the most immediate risks.

However, the architecture relies on a **Service Account** (Nextcloud Admin) to facilitate file access. This is the single critical point of failure: a compromise of the Node.js container equals a compromise of the entire Nextcloud instance if not properly isolated.

## Vulnerability Matrix

| ID | Risk | Severity | Component | Status |
|----|------|----------|-----------|--------|
| **V1** | **Excessive Privilege (Service Account)** | **CRITICAL** | Collab Server | **Mitigated (Operational)** |
| **V2** | Denial of Service (Memory/Connections) | HIGH | Collab Server | Unmitigated |
| **V3** | Stored XSS (Malicious Assets) | MEDIUM | Collab Server | Partially Mitigated (NC CSP) |
| **V4** | JWT Token in URL | LOW | Frontend/WS | **Mitigated** (60s Expiry) |
| **V5** | Cross-Site WebSocket Hijacking | LOW | WebSocket | Partially Mitigated (Token Fetch) |
| **V6** | **Read-Only Bypass** | **HIGH** | WebSocket | **Fixed** (Server-side Filter) |

## Detailed Analysis

### V1: The "God Mode" Service Account
*   **Description:** The Node.js server uses `NC_USER` credentials to read/write files via WebDAV.
*   **Impact:** Attacker gains full administrative control over Nextcloud files.
*   **Status:** **Mitigated via Configuration.** The documentation now strictly mandates creating a dedicated `tldraw-bot` service user rather than using personal admin credentials. This allows for revocation and auditing.

### V2: Denial of Service (DoS)
*   **Description:** No rate limiting on WebSocket connections or file uploads.
*   **Impact:** Service outage; potential OOM crash of the Docker container.
*   **Remaining Work:** Implement `express-rate-limit` and configure Docker resource limits (`mem_limit`).

### V3: Stored XSS via Assets
*   **Description:** The server accepts uploads based on the user-provided `Content-Type`.
*   **Impact:** If Nextcloud renders the file inline, scripts embedded in SVGs or HTML could execute.
*   **Mitigation:** Nextcloud's CSP provides some protection.
*   **Remaining Work:** Implement server-side file type verification (Magic Bytes) and SVG sanitization (e.g., `dompurify`).

### V4: JWT Token in URL
*   **Description:** WebSocket URL contains the token.
*   **Status:** **Fixed.** Tokens are now short-lived (60s), making replay attacks from logs impractical.

### V5: Cross-Site WebSocket Hijacking (CSWSH)
*   **Description:** A malicious site could open a WebSocket connection.
*   **Status:** **Partially Mitigated.** The attacker cannot easily obtain a valid JWT because the `/token` endpoint is protected by Nextcloud's Same-Origin policy.
*   **Remaining Work:** Uncomment and enforce the `Origin` header check in `server.ts` for defense-in-depth.

### V6: Read-Only Enforcement
*   **Description:** A read-only client could manually send write messages to the WebSocket.
*   **Status:** **Fixed.** The server now inspects the `canWrite` claim in the JWT and wraps the WebSocket to silently drop any `update` messages from read-only sessions.

## Conclusion

The application is safe for **trusted internal environments** provided the **Service User** is isolated. For public-facing deployments, addressing **V2 (DoS)** and **V3 (Asset Validation)** is highly recommended.
