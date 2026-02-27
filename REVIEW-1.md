# Code Review: Nextcloud tldraw App

**Review Date:** 2026-02-27
**Codebase Version:** v0.0.1 (main branch, post-PR #16)
**Scope:** Full codebase — PHP app, Node.js collab server, React frontend, infrastructure

This document summarises findings from four independent reviewers who evaluated the codebase in parallel from distinct perspectives: security, FLOSS licensing, UX, and end-user feature completeness.

---

## Executive Summary

The app is **architecturally sound** with genuine strengths: the WOPI-style callback model eliminates the need for service-account credentials, JWT dual-token design is well-thought-out, and Nextcloud integration follows framework conventions. However, several issues across all four review dimensions need attention before this can be recommended for broad production deployment or Nextcloud App Store submission.

| Dimension | Overall Rating | Most Critical Issue |
|-----------|---------------|---------------------|
| Security | 6/10 | WebSocket rate limiting bypassed; JWT algorithm not validated; CORS open |
| FLOSS / Licensing | 5/10 | tldraw is proprietary (production-restricted), incompatible with AGPL-3.0 |
| UX | 5/10 | Vague errors ("check console"), no connection status, no save indicator |
| Feature Completeness | 5/10 | Anonymous collaboration, no templates, no version history |

---

## 1. Security Review

### Strengths

The following are correctly and robustly implemented:

- **HMAC-SHA256 JWT signatures** with constant-time comparison (`hash_equals()` in PHP) — timing-safe.
- **Magic-byte file validation** in addition to MIME type allowlisting (PNG, JPEG, GIF, WebP) — SVG intentionally rejected.
- **Path traversal protection** on asset keys (`str_contains` checks for `/` and `..`).
- **Origin validation on WebSocket upgrades** — CSWSH is blocked when `NC_URL` is set.
- **Read-only enforcement at two layers**: server-side JWT claim and a WebSocket message proxy that drops `update` messages.
- **No service account required** — file I/O uses scoped, file-specific storage tokens.
- **Two-token design** — short-lived 60 s WebSocket JWT + 8 h storage token limit the blast radius of token theft.

### Findings

#### CRITICAL — CORS Open to All Origins
**File:** `collab-server/src/server.ts:36`

```ts
app.use(cors());   // zero configuration
```

HTTP routes (including `/uploads`) accept requests from any origin. WebSocket upgrades correctly enforce `origin === NC_URL.origin` (line 198), but HTTP routes do not. The presence of JWT validation on the upload endpoint mitigates direct exploitation, but the architectural inconsistency violates secure-by-default principles and is tracked as Issue #13.

**Fix:** Restrict to Nextcloud origin:
```ts
app.use(cors({
    origin: process.env.NC_URL ? new URL(process.env.NC_URL).origin : false,
    methods: ['POST', 'GET', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization'],
}));
```

---

#### HIGH — WebSocket Rate Limiting Bypassed
**File:** `collab-server/src/server.ts:170–217`
**Tracked:** Issue #11

`express-rate-limit` applies to Express routes only. The WebSocket upgrade runs on the raw HTTP `server.on('upgrade', ...)` event, **before** Express middleware is evaluated. Per-IP limits exist (`MAX_WS_CONNECTIONS_PER_IP = 10`) but apply only to concurrent connections, not to the rate of new connections over time. An attacker with a valid 60 s token can:

1. Open connections from multiple IPs (proxy rotation).
2. Exhaust Node.js memory via unbounded room creation — each room allocates an in-memory SQLite database.

**Fix:** Implement a sliding-window rate limiter inside the upgrade handler:
```ts
const WS_RATE = new Map<string, { count: number; reset: number }>();
server.on('upgrade', (req, socket, head) => {
    const ip = getClientIp(req);
    const now = Date.now();
    const entry = WS_RATE.get(ip);
    if (entry && entry.reset > now) {
        if (entry.count >= 5) { socket.write('HTTP/1.1 429 ...\r\n\r\n'); socket.destroy(); return; }
        entry.count++;
    } else {
        WS_RATE.set(ip, { count: 1, reset: now + 60_000 });
    }
    // ... existing upgrade logic
});
```

---

#### HIGH — JWT Algorithm Not Validated
**Files:** `collab-server/src/server.ts:51–71`, `lib/Controller/FileController.php:259–283`

Both the Node.js and PHP JWT verifiers parse and verify the signature but never read the `alg` field in the header. The signature is always computed with HS256 regardless, so a `alg: none` attack does not succeed *today*, but:

- The implementation is one refactor away from being vulnerable if algorithm selection is ever made dynamic.
- It violates JWT best practices (RFC 8725).

**Fix (Node.js):**
```ts
const header = JSON.parse(Buffer.from(headerB64, 'base64url').toString());
if (header.alg !== 'HS256') return null;
```

**Fix (PHP):**
```php
$header = json_decode(base64_decode(strtr($headerB64, '-_', '+/')), true);
if (($header['alg'] ?? '') !== 'HS256') return null;
```

---

#### MEDIUM — Room Tokens Logged in Plaintext
**File:** `collab-server/src/room-manager.ts:39,52`

```ts
console.log(`Room created: ${roomToken} for file ${fileId}`);
console.log(`Room closed: ${roomToken}`);
```

Room tokens are deterministic HMACs of `fileId`. If logs are shipped to an external aggregator, tokens are visible to anyone with log access. Remove the token from log output; the `fileId` alone is sufficient for debugging.

---

#### MEDIUM — No Rate Limiting on Token Endpoint
**File:** `lib/Controller/TldrawController.php:78`

Any authenticated Nextcloud user can call `/token/{fileId}` in an unbounded loop, enumerating file IDs by observing 200 vs. 403 vs. 404 response codes. A Nextcloud-level rate limiter (or a simple per-user counter in app config) would close this.

---

#### MEDIUM — Clock Skew Not Tolerated in Token Expiry
**Files:** `collab-server/src/server.ts:65`, `lib/Controller/FileController.php:280`

Expiry is compared with `time() > exp` and `Date.now() / 1000 > exp` with no skew window. If PHP server and Node server clocks differ by more than a few seconds, legitimate tokens may be rejected. Add a 60 s tolerance on the receiving side.

---

#### LOW — Traefik Dashboard Exposed
**File:** `docker-compose.yml:40,52–53`
**Tracked:** Issue #12

```yaml
- "--api.insecure=true"
ports:
  - "8080:8080"
```

The Traefik management dashboard is reachable without authentication, disclosing all routing rules, TLS domains, and backend health. Remove `--api.insecure=true` and the `8080` port binding for production; access via Docker internal network only if needed.

---

#### LOW — WebSocket Token in URL Query Parameter
**File:** `src/TldrawEditor.tsx:29`

The JWT is appended as `?token=...` to the WebSocket URL, making it visible in proxy logs, browser history, and Referrer headers. The 60 s expiry significantly limits the risk. Document this trade-off rather than changing it (alternatives require library-level changes), and ensure Traefik access logs are not retained long-term.

---

### Security: Deployment Blockers

Before public-facing production deployment, fix:

- [ ] CORS restriction (CRITICAL — C1)
- [ ] WebSocket rate limiting (HIGH — H1, Issue #11)
- [ ] JWT algorithm validation (HIGH — H3)
- [ ] Traefik dashboard (LOW — L1, Issue #12)

---

## 2. FLOSS Licensing Review

### Critical — tldraw Is Proprietary (Production-Restricted)

**Files:** `package.json`, `collab-server/package.json`

The app declares `AGPL-3.0` in `appinfo/info.xml` and carries the full license text. All non-tldraw dependencies use MIT, Apache-2.0, or BSD-2 licences — compatible with AGPL.

However, the three core dependencies `tldraw@4.4.0`, `@tldraw/sync@4.4.0`, and `@tldraw/sync-core@4.4.0` are published under **tldraw's own proprietary licence**, which requires a paid production licence key. `README.md:141` describes tldraw as "the open-source whiteboard library" — this is inaccurate; tldraw is *source-available* for development, not OSI-certified open source.

**Why this matters:**

| Scenario | Impact |
|----------|--------|
| AGPL-3.0 distribution | Copyleft clause requires all derivative works to be free software — a proprietary production dependency violates this |
| Nextcloud App Store submission | NC App Store requires AGPL and AGPL-compatible dependencies — this app will likely be rejected as-is |
| Self-hosted deployment | Operators may be unknowingly non-compliant with tldraw's production licence |

**Immediate action:** Add a `docs/LEGAL.md` that clearly states the tldraw dependency situation and what operators must verify before production deployment. Update `README.md:141` to remove the "open-source" characterisation of tldraw.

---

### Missing Copyright Headers

**All source files** (PHP, TypeScript, TSX) begin without a copyright notice or SPDX identifier. AGPL-3.0 requires copyright notice in redistributed files.

Add to every `.php`, `.ts`, `.tsx` file:
```
/**
 * Nextcloud tldraw
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 <author>
 */
```

---

### Missing Community Health Files

The `docs/` folder contains high-quality internal documentation (ARCHITECTURE.md, DEPLOYMENT.md, USAGE.md, ADMINISTRATION.md), but public contributor infrastructure is absent:

| File | Status | Impact |
|------|--------|--------|
| `CONTRIBUTING.md` | Missing | New contributors have no guide |
| `CODE_OF_CONDUCT.md` | Missing | No stated community norms |
| `SECURITY.md` | Missing | No responsible disclosure channel |
| `CHANGELOG.md` | Missing | Release history not tracked |
| `.github/ISSUE_TEMPLATE/` | Missing | Bug reports lack structure |
| `.github/pull_request_template.md` | Missing | PRs lack consistent format |

The project's internal workflow rules (branch naming, no AI attribution, PR reviewer check) are captured only in `CLAUDE.md`. These should be surfaced in `CONTRIBUTING.md` for external contributors.

---

### Vendor Lock-in Assessment

| Dependency | Lock-in Level | Notes |
|------------|--------------|-------|
| tldraw | CRITICAL | Entire editor, sync, and file format depend on tldraw |
| `.tldr` format | HIGH | Proprietary format; no published specification |
| Nextcloud | LOW (by design) | Standard Nextcloud PHP APIs only |
| WebSocket / JWT | None | Open standards |

The `.tldr` file format is defined entirely by tldraw's internal serialisation. Users' drawings cannot be opened by any other tool. This is an unavoidable consequence of building on tldraw, but it should be documented prominently so operators can make an informed deployment decision.

---

### FLOSS: Priority Actions

1. Add `docs/LEGAL.md` disclosing tldraw's production licence requirement.
2. Correct "open-source" description of tldraw in `README.md`.
3. Add SPDX copyright headers to all source files.
4. Create `CONTRIBUTING.md`, `SECURITY.md`, `CHANGELOG.md`.
5. Clarify Nextcloud App Store eligibility with tldraw team before submission.

---

## 3. UX Review

### Error Messaging — Critical Gaps

**File:** `src/main.tsx:37`

```ts
document.body.textContent = 'Error loading drawing. Please check console.';
```

This is the only fallback for any initialisation failure. End users cannot open the browser console; the message is useless to them. Replace with a styled, actionable message:

> *"Unable to load the drawing. Please refresh the page. If the problem persists, contact your administrator."*

Other error surfaces:

| Location | Current message | Problem |
|----------|----------------|---------|
| `src/asset-store.ts:23` | `Upload failed: {statusText}` | `statusText` may be empty or technical |
| `lib/Controller/FileController.php:74` | `"Read failed"` | No context (permissions? disk full?) |
| `templates/admin.php:107` | `"Failed to save settings. Make sure you are an administrator."` | Acceptable, but doesn't say *why* |

---

### No Loading or Save Indicators

**Files:** `src/main.tsx:17–21`, `collab-server/src/room-manager.ts:44–49`

The token is fetched asynchronously before the editor renders. During this time, users see a blank screen with no indication the app is loading. The auto-save runs every 30 seconds silently; users cannot tell whether their changes are persisted.

**Recommended additions:**
- A spinner in `#tldraw-root` while the token is being fetched.
- A "Saving…" / "Saved at HH:MM" indicator using tldraw's instance state mechanism.
- A save-failure banner if the 30 s flush returns an error.

---

### Offline and Disconnection Handling

**File:** `src/TldrawEditor.tsx`

If the WebSocket drops, `@tldraw/sync` attempts reconnection internally. There is no app-level indicator. Users do not know:
- Whether they are connected.
- Whether their recent edits have been saved.
- Whether they should refresh the page.

A persistent, subtle connection status badge (green/yellow/red dot) would address this with minimal UI overhead.

---

### Read-Only Mode Is Silent

**File:** `src/TldrawEditor.tsx:39`

```ts
const store = useSync({ isReadOnly: !canWrite, ... });
```

The editor becomes read-only based on the JWT claim, but nothing in the UI tells the user they are in view-only mode. Users may think the editor is broken. Add a banner: *"You have view-only access to this drawing."*

---

### Collaboration Is Anonymous

**File:** `src/TldrawEditor.tsx`, `collab-server/src/room-manager.ts`

Real-time cursor sharing works via `@tldraw/sync`, but no user identity is attached. Collaborators see cursors move and shapes appear without knowing who is making changes. Injecting the Nextcloud display name into the sync connection's user metadata would expose this through tldraw's existing presence UI.

---

### Partial Internationalisation

**Files:** `templates/admin.php` (uses `$l->t()`), `src/main.tsx`, `src/asset-store.ts`

Admin-panel strings are correctly wrapped in Nextcloud's translation function. All React-side error messages are hardcoded English strings. Non-English users of the frontend receive English error messages. A lightweight i18n pass on the handful of React strings would complete this.

---

### Admin Settings UX

**File:** `templates/admin.php`

Issues:
- No "Test Connection" button to verify the collab server is reachable before saving.
- No guidance on how to generate a JWT secret (`openssl rand -hex 32`).
- Status messages auto-hide after 4 seconds — easily missed.
- No indication of required field format (URL must be `https://...`; secret must be 64 hex chars).

---

### Accessibility Gaps

- The fallback error at `src/main.tsx:37` is injected as plain `textContent`, not wrapped in `role="alert"` — invisible to screen readers.
- No ARIA labels on the React wrapper elements.
- Admin settings colour choices (`#856404` warning text) are not verified against WCAG AA contrast ratios.
- Form fields in admin settings lack `required` attributes.

---

### UX: Priority Actions

1. Replace console-error fallback with a user-facing error modal.
2. Add connection status indicator.
3. Show read-only mode banner when `canWrite` is false.
4. Add loading spinner during token fetch.
5. Add save status indicator.
6. "Test Connection" button in admin settings.
7. i18n for React error strings.
8. Wrap errors in `role="alert"` for screen reader accessibility.

---

## 4. Feature Completeness (Whiteboard User Perspective)

This section evaluates what a power user of collaborative whiteboard tools (Miro, FigJam, Excalidraw) would find present, missing, or frustrating.

### What Works Well

- **Real-time sync** — confirmed working via `@tldraw/sync`, feels snappy.
- **File-level permissions** — sharing a `.tldr` file immediately controls edit access; no separate board invite needed.
- **Self-hosted, privacy-first** — no external telemetry, all data on the operator's server.
- **Auto-save** — 30 s interval, no manual save button needed.
- **Standard tldraw tools** — shapes, text, arrows, sticky notes, frames are all available.
- **Export** — PNG, SVG, JSON available via tldraw's built-in export menu.

### Notable Gaps

#### No User Identity in Cursors

Collaborators' cursors are visible but anonymous. Attaching the Nextcloud display name to the user's sync presence object (passed to `useSync()`) would show names on cursors at near-zero implementation cost.

#### No Comments or Annotations

There is no way to add comments to shapes or regions of the canvas. In Miro/FigJam, comments are the primary async feedback mechanism. Without them, teams must route feedback through separate chat channels.

#### No Templates

Every drawing starts from a blank canvas. There are no built-in templates for common use cases (flowcharts, wireframes, retrospective boards, org charts). Adding a handful of `.tldr` template files and wiring them into Nextcloud's template picker would significantly improve the first-use experience for structured work.

#### No Version History or Rollback

The room state is a single JSON document flushed every 30 s. There is no snapshot history. If a collaborator accidentally deletes shapes, there is no way to recover beyond per-session Ctrl+Z. Nextcloud's built-in file versioning *may* provide coarse recovery (one version per save), but this is not surfaced in the editor UI.

#### No Public or Read-Only Share Link

Files can be shared via Nextcloud's standard sharing. There is no way to generate a lightweight preview URL for stakeholders who do not have a Nextcloud account. This is a significant limitation for design review workflows.

#### Limited Asset Support

- **Supported:** JPEG, PNG, GIF, WebP (correctly validated with magic bytes).
- **Blocked:** SVG (intentionally, pending XML sanitisation — documented in `CLAUDE.md` and `docs/USAGE.md`).
- **Not supported:** Video, audio, hyperlinks on shapes.

The SVG restriction is a reasonable security trade-off; the others are expected gaps for v0.0.1.

#### No Nextcloud Activity Feed Integration

Drawing edits do not appear in Nextcloud's activity stream. Users cannot see "Alice edited Roadmap.tldr at 14:32" from the Files app. The Nextcloud `IActivityManager` API could be used to emit events on file save.

### Competitive Comparison

| Feature | This App | Excalidraw | Miro | FigJam |
|---------|----------|-----------|------|--------|
| Real-time collab | ✓ | ✓ | ✓ | ✓ |
| Named cursors | ✗ | ✗ (self-hosted only) | ✓ | ✓ |
| Comments | ✗ | ✗ | ✓ | ✓ |
| Templates | ✗ | Minimal | ✓✓ | ✓✓ |
| Version rollback | ✗ (coarse via NC) | ✗ | ✓ | ✓ |
| Public share link | ✗ | ✓ | ✓ | ✓ |
| Offline mode | ✗ | ✓ | ✗ | ✗ |
| Self-hosted | ✓ | ✓ | ✗ | ✗ |
| Nextcloud-native files | ✓ | ✗ | ✗ | ✗ |
| No external account required | ✓ | Varies | ✗ | ✗ |

### Feature: Priority Backlog (Suggested)

1. **Named cursors** — inject Nextcloud display name into `useSync()` user metadata. (Low effort, high impact.)
2. **Read-only link sharing** — already partially architected (`canWrite: false` in JWT). (Low effort.)
3. **"You are in view-only mode" banner.** (Low effort.)
4. **Template selection on file creation** — expand `RegisterTemplateCreatorListener` with template variants. (Medium effort.)
5. **Activity feed integration** — emit events on flush. (Medium effort.)
6. **Nextcloud file preview thumbnail** — implement `IPreviewProvider`. (Medium effort.)
7. **Version history UI** — surface Nextcloud file versions in the editor sidebar. (High effort.)
8. **Comments** — significant feature requiring new data model. (High effort.)

---

## Cross-Cutting Observations

### Positive Architectural Decisions

- The collab server has no Nextcloud credentials at all — the WOPI-style callback model is genuinely good design that eliminates the most common class of Nextcloud app vulnerabilities.
- Vite's `inlineDynamicImports: true` produces a single self-contained bundle, simplifying deployment.
- File-scoped storage tokens mean a compromised token cannot access other users' files.
- `index.css` uses `isolation: isolate` to prevent Nextcloud's global CSS from bleeding into the tldraw canvas.

### Technical Debt to Address

- The in-memory SQLite room store (`new DatabaseSync(':memory:')`) means any crash loses unsaved room state. A graceful shutdown handler that flushes all rooms before exit is missing.
- No request timeout on the `fetch()` calls from the collab server to Nextcloud (`collab-server/src/nc-storage.ts`) — a slow Nextcloud response can stall a room indefinitely.
- The `@types/node ^22.0.0` requirement (documented in `CLAUDE.md`) is not enforced in a `.nvmrc` or `engines` field in `package.json`, so developers on Node 18/20 will get confusing type errors.

---

## Summary of Recommended Actions

### Must Fix Before Production

| # | Finding | Area | Effort |
|---|---------|------|--------|
| 1 | Restrict CORS to Nextcloud origin | Security | Small |
| 2 | Add WebSocket new-connection rate limiter | Security | Small |
| 3 | Validate `alg` field in JWT header | Security | Tiny |
| 4 | Disable Traefik insecure dashboard | Security | Tiny |
| 5 | Add `docs/LEGAL.md` disclosing tldraw production licence | FLOSS | Small |
| 6 | Replace "check console" error with user-facing message | UX | Small |

### Should Fix Soon

| # | Finding | Area | Effort |
|---|---------|------|--------|
| 7 | Remove room tokens from log output | Security | Tiny |
| 8 | Add clock skew tolerance to token expiry | Security | Tiny |
| 9 | Add rate limiting to `/token` endpoint | Security | Small |
| 10 | Add SPDX copyright headers to all source files | FLOSS | Small |
| 11 | Create `CONTRIBUTING.md`, `SECURITY.md`, `CHANGELOG.md` | FLOSS | Medium |
| 12 | Connection status indicator (online/offline badge) | UX | Small |
| 13 | Loading spinner during token fetch | UX | Tiny |
| 14 | Save status indicator | UX | Small |
| 15 | "View-only mode" banner when `canWrite` is false | UX/Feature | Tiny |
| 16 | Named cursors (inject display name into sync user) | Feature | Small |
| 17 | Add graceful shutdown handler flushing all rooms | Reliability | Small |
| 18 | Add request timeout to `ncFetch()` | Reliability | Tiny |

### Consider for v0.1.0

| # | Finding | Area | Effort |
|---|---------|------|--------|
| 19 | Template selection on new-file creation | Feature | Medium |
| 20 | Nextcloud activity feed integration | Feature | Medium |
| 21 | File preview thumbnail (`IPreviewProvider`) | Feature | Medium |
| 22 | i18n for React-side strings | UX | Small |
| 23 | "Test Connection" button in admin settings | UX | Small |
| 24 | `engines` field in `package.json` requiring Node 22+ | DX | Tiny |
| 25 | GitHub issue templates and PR template | FLOSS | Small |

---

*Review conducted 2026-02-27 against commit `a5201f6` (main).*
