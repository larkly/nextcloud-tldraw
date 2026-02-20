# Administration Guide

This guide covers administrative tasks for managing the tldraw Nextcloud app and its backend services.

## Updates & Maintenance

### Updating the Collab Server

The Collab Server runs as a Docker container. The pre-built image is published to GHCR automatically on every release.

1.  Pull the latest image:
    ```bash
    docker compose pull tldraw-sync
    ```
2.  Restart the container:
    ```bash
    docker compose up -d tldraw-sync
    ```

To update to a specific release version, edit `docker-compose.yml` and change the image tag:
```yaml
image: ghcr.io/larkly/nextcloud-tldraw:v0.1.0
```
Then re-run `docker compose up -d tldraw-sync`.

If you are building from source instead of using the GHCR image:
```bash
docker compose build tldraw-sync
docker compose up -d tldraw-sync
```

### Updating the Nextcloud App

1.  Check for a new release at [github.com/larkly/nextcloud-tldraw/releases](https://github.com/larkly/nextcloud-tldraw/releases).
2.  Download and extract the release archive into your Nextcloud apps directory:
    ```bash
    tar -xzf nextcloud-tldraw-<version>.tar.gz -C /var/www/nextcloud/apps/
    ```
3.  Run the Nextcloud upgrade command:
    ```bash
    php occ app:update tldraw
    ```

---

## Configuration Reference

### Nextcloud Admin Settings
Accessible at **Administration Settings > tldraw**.

| Setting | Description | Example |
|---|---|---|
| **Collab Server URL** | Public HTTPS URL of the Collab Server | `https://tldraw.example.com` |
| **JWT Secret** | Shared secret key — must match `JWT_SECRET_KEY` in `.env` | (hidden) |

### Environment Variables (`.env`)

| Variable | Required | Description | Example |
|---|---|---|---|
| `JWT_SECRET_KEY` | Yes | Shared secret (32-byte hex). Generate with `openssl rand -hex 32` | `a1b2c3...` |
| `NC_URL` | Yes | Base URL of your Nextcloud instance (no trailing slash) | `https://cloud.example.com` |
| `NC_USER` | Yes | Dedicated Service User (bot) username | `tldraw-bot` |
| `NC_PASS` | Yes | App Password for the bot (not the login password) | `abcd-efgh-ijkl-mnop` |
| `TLDRAW_HOST` | Yes | Domain for Traefik routing | `tldraw.example.com` |
| `ACME_EMAIL` | Yes (Traefik) | Email for Let's Encrypt notifications | `admin@example.com` |
| `PORT` | No | Internal container port (default: `3000`) | `3000` |

---

## Troubleshooting

### "Error loading drawing" or blank editor

-   **Cause:** The token endpoint is unreachable or the Nextcloud app is misconfigured.
-   **Fix:**
    1.  Open browser DevTools → **Network** tab, reload the page.
    2.  Look for a failing request to `/apps/tldraw/token/...`. Check the response status.
    3.  Ensure the tldraw app is enabled: `php occ app:enable tldraw`.

### "WebSocket Connection Failed"

-   **Cause:** Traefik misconfiguration, firewall blocking port 443, or SSL issue.
-   **Fix:**
    1.  Check the health endpoint: `curl https://tldraw.example.com/health`
    2.  Ensure Traefik handles WebSocket upgrades (check the middleware labels in `docker-compose.yml`).
    3.  Verify SSL certificates are valid for the Collab domain.
    4.  Check container logs: `docker compose logs -f tldraw-sync`

### "Failed to save" / WebDAV errors

-   **Cause:** The Service User (`NC_USER`) cannot access the file via WebDAV.
-   **Fix:**
    1.  Ensure `tldraw-bot` is in the **admin** group in Nextcloud.
    2.  Verify `NC_PASS` is a valid **App Password** (not the login password).
    3.  Look for `401 Unauthorized` or `403 Forbidden` in container logs.

### JWT secret mismatch

-   **Symptom:** WebSocket connections are immediately closed with 403.
-   **Fix:** Ensure `JWT_SECRET_KEY` in `.env` exactly matches the **JWT Secret** saved in Nextcloud Admin Settings. Restart the container after changing `.env`.

### Asset upload rejected (400 error)

-   **Cause:** Unsupported file type. SVG uploads are intentionally disabled.
-   **Supported types:** JPEG, PNG, GIF, WebP.

---

## Logs

-   **Collab Server:** `docker compose logs -f tldraw-sync`
-   **Nextcloud:** `tail -f /var/www/nextcloud/data/nextcloud.log` (filter for `OCA\Tldraw` entries)
