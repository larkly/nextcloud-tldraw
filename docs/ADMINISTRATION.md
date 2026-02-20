# Administration Guide

This guide covers day-to-day administration of the tldraw Nextcloud app and its Collab Server backend.

---

## Updates & Maintenance

### Updating the Collab Server

New images are published automatically to GHCR on every release. To update:

```bash
docker compose pull tldraw-sync
docker compose up -d tldraw-sync
```

To update to a specific version rather than `latest`, change the image tag in `docker-compose.yml` before pulling:

```yaml
image: ghcr.io/larkly/nextcloud-tldraw:v0.1.0
```

If you are building from source:

```bash
docker compose build tldraw-sync
docker compose up -d tldraw-sync
```

### Updating the Nextcloud App

1. Download the new release archive from [github.com/larkly/nextcloud-tldraw/releases](https://github.com/larkly/nextcloud-tldraw/releases).
2. Extract it into your Nextcloud apps directory (this overwrites the existing files):
   ```bash
   tar -xzf nextcloud-tldraw-<version>.tar.gz -C /var/www/nextcloud/apps/
   ```
3. Run the upgrade command to apply any database migrations:
   ```bash
   php occ app:update tldraw
   ```

---

## Configuration Reference

### Nextcloud Admin Settings

Accessible at **Administration Settings > tldraw**.

| Setting | Description |
|---|---|
| **Collab Server URL** | Public HTTPS URL of the Collab Server (e.g. `https://tldraw.example.com`) |
| **JWT Secret** | Shared secret — must exactly match `JWT_SECRET_KEY` in the Collab Server `.env` |

### Collab Server Environment Variables

Defined in `.env` next to `docker-compose.yml`.

| Variable | Required | Description |
|---|---|---|
| `JWT_SECRET_KEY` | Yes | 32-byte hex secret. Generate with `openssl rand -hex 32`. Must match Nextcloud Admin Settings. |
| `NC_URL` | Yes | Base URL of your Nextcloud instance, no trailing slash (e.g. `https://cloud.example.com`) |
| `NC_USER` | Yes | Username of the dedicated Service User bot (e.g. `tldraw-bot`) |
| `NC_PASS` | Yes | **App Password** for the bot — not the login password |
| `TLDRAW_HOST` | Yes | Domain for Traefik routing (e.g. `tldraw.example.com`) |
| `ACME_EMAIL` | Yes* | Email for Let's Encrypt notifications. *Only required if using the bundled Traefik service. |
| `PORT` | No | Internal container port. Default: `3000`. Do not change unless you have a port conflict. |

---

## Troubleshooting

### Editor shows "Error loading drawing" or a blank page

**Cause:** The browser cannot reach the token endpoint or the app is not enabled.

**Diagnose:**
1. Open browser DevTools → **Network** tab, reload the page.
2. Find the request to `/apps/tldraw/token/<id>` and check its status code:
   - `404` — the tldraw app is not enabled. Run `php occ app:enable tldraw`.
   - `500` — the JWT Secret has not been saved in Admin Settings yet.
   - `403` — the user does not have read access to this file.
3. Check the Nextcloud log for `OCA\Tldraw` entries: `tail -f /var/www/nextcloud/data/nextcloud.log`

### WebSocket connection fails (drawing loads but collaboration doesn't work)

**Cause:** Traefik misconfiguration, SSL issue, or firewall blocking port 443.

**Diagnose:**
1. Test the health endpoint from the server: `curl https://tldraw.example.com/health`
   - If this fails, the container is not reachable. Check `docker compose logs tldraw-sync`.
2. Ensure Traefik is proxying WebSocket upgrades — the `tldraw-websocket` middleware in `docker-compose.yml` must be applied to the router.
3. Confirm SSL certificates are valid: `curl -v https://tldraw.example.com/health 2>&1 | grep -i ssl`

### Drawing changes are not saved / "Failed to save" in logs

**Cause:** The Service User cannot write to Nextcloud via WebDAV.

**Diagnose:**
1. Check container logs for `401 Unauthorized` or `403 Forbidden`: `docker compose logs tldraw-sync`
2. Verify the bot user is in the **admin** group in Nextcloud (**Users** panel).
3. Confirm `NC_PASS` is a valid **App Password** (not the account login password). Regenerate one if unsure.

### WebSocket connections are immediately closed with 403

**Cause:** JWT secret mismatch between Nextcloud and the Collab Server.

**Fix:** Ensure `JWT_SECRET_KEY` in `.env` is exactly equal to the **JWT Secret** saved in Nextcloud Admin Settings (no extra spaces or newlines). After changing `.env`, restart the container:

```bash
docker compose up -d tldraw-sync
```

### Image upload returns a 400 error

**Cause:** Unsupported file type. SVG uploads are intentionally rejected for security reasons.

**Supported formats:** JPEG, PNG, GIF, WebP.

---

## Logs

| Component | Command |
|---|---|
| Collab Server | `docker compose logs -f tldraw-sync` |
| Nextcloud | `tail -f /var/www/nextcloud/data/nextcloud.log` (filter for `OCA\Tldraw`) |
