# Administration Guide

This guide covers administrative tasks for managing the tldraw Nextcloud app and its backend services.

## Updates & Maintenance

### Updating the Nextcloud App

1.  Check for updates in the Nextcloud App Store (if published) or download a new release archive.
2.  Extract the new version into `/var/www/nextcloud/apps/tldraw/`.
3.  Run the update command:
    ```bash
    php occ app:update tldraw
    ```

### Updating the Collab Server

1.  Pull the latest Docker image (or rebuild):
    ```bash
    docker-compose pull
    # OR if building from source
    docker-compose build
    ```
2.  Restart the container:
    ```bash
    docker-compose up -d
    ```

## Configuration Reference

### Nextcloud Admin Settings
Accessible at **Administration Settings > tldraw**.

| Setting | Description | Example |
|---|---|---|
| **Collab Server URL** | Public URL of the Node.js backend | `https://tldraw.example.com` |
| **JWT Secret** | Shared secret key (must match `.env`) | (Hidden) |

### Environment Variables (`.env`)
Located in the `collab-server` directory (or wherever `docker-compose.yml` is).

| Variable | Description | Example |
|---|---|---|
| `JWT_SECRET_KEY` | Shared secret key (32 bytes hex) | `a1b2c3d4...` |
| `NC_URL` | Base URL of your Nextcloud instance | `https://cloud.example.com` |
| `NC_USER` | Dedicated Service User (Bot) | `tldraw-bot` |
| `NC_PASS` | App Password for the bot | `abcd-efgh-ijkl-mnop` |
| `PORT` | Internal port (Docker only) | `3000` |
| `TLDRAW_HOST` | Traefik domain for routing | `tldraw.example.com` |

## Troubleshooting

### "Error: Failed to fetch token"
-   **Cause:** The Nextcloud app cannot communicate with the Collab Server URL from the browser, or the JWT secret is missing.
-   **Fix:**
    1.  Verify the **Collab Server URL** in Nextcloud settings is correct and accessible (try opening it in a browser).
    2.  Check the browser console (Network tab) for errors on `/apps/tldraw/token/...`.

### "WebSocket Connection Failed"
-   **Cause:** Traefik misconfiguration or firewall blocking port 443/3000.
-   **Fix:**
    1.  Ensure Traefik handles WebSocket upgrades (check `docker-compose.yml` labels).
    2.  Verify SSL certificates are valid for the Collab domain.
    3.  Check Collab Server logs: `docker-compose logs -f tldraw-sync`.

### "Permission Denied" (WebDAV Errors)
-   **Cause:** The Service User (`NC_USER`) does not have permission to access the file.
-   **Fix:**
    1.  Ensure the Service User is in the **admin** group in Nextcloud.
    2.  Verify the App Password is valid.
    3.  Check logs for `401 Unauthorized` or `403 Forbidden` from Nextcloud.

### Logs
-   **Collab Server:** `docker-compose logs -f tldraw-sync`
-   **Nextcloud:** `tail -f /var/www/nextcloud/data/nextcloud.log` (look for `OCA\Tldraw` entries).
