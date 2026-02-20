# Deployment Guide

This guide covers the deployment of the **Collab Server**, the Node.js backend required for real-time collaboration.

## Prerequisites

-   **Docker** and **Docker Compose v2** (`docker compose`) installed on the server.
-   **Traefik** (recommended) or another reverse proxy for SSL termination.
-   A **dedicated domain** for the collab server (e.g., `tldraw.example.com`).

## Step 1: Prepare the Environment

1.  Create a directory for the service on your server:
    ```bash
    mkdir -p /opt/tldraw-sync && cd /opt/tldraw-sync
    ```
2.  Copy `docker-compose.yml` and `.env.example` from the repository into this directory:
    ```bash
    # from the repository root
    cp docker-compose.yml .env.example /opt/tldraw-sync/
    ```
3.  Rename `.env.example` to `.env`:
    ```bash
    cp .env.example .env
    ```

## Step 2: Create a Service User

The Collab Server reads and writes `.tldr` files via WebDAV on behalf of all users. For security, this must be a dedicated "bot" account — never your personal admin account.

1.  **Create User:** In Nextcloud, go to **Users** and create a new user (e.g., `tldraw-bot`).
2.  **Grant Permissions:** Add `tldraw-bot` to the `admin` group.
    -   *Why admin?* Only admin users can access `/remote.php/dav/files/<other_user>/` to save files owned by other people.
3.  **Generate an App Password** (do not use the login password):
    -   Log in as `tldraw-bot`.
    -   Go to **Settings > Security > Devices & sessions**.
    -   Create a new App Password named `Collab Server`.
    -   Copy the password — it will only be shown once.

## Step 3: Configure Environment

Edit `.env` with your values:

```bash
# Security: generate a secure 32-byte hex string, e.g.:
#   openssl rand -hex 32
# This value must match the 'JWT Secret' in the Nextcloud Admin Settings.
JWT_SECRET_KEY=change_me_to_a_random_32_byte_hex_string

# Base URL of your Nextcloud instance (no trailing slash)
NC_URL=https://nextcloud.example.com

# Service User credentials (from Step 2)
NC_USER=tldraw-bot
NC_PASS=your-generated-app-password

# Domain for the collab server (used by Traefik)
TLDRAW_HOST=tldraw.example.com

# Email for Let's Encrypt certificate notifications
ACME_EMAIL=admin@example.com
```

## Step 4: Configure Traefik

The provided `docker-compose.yml` includes Traefik v3 labels on the `tldraw-sync` service.

### If you already have Traefik running:
1.  Remove the `traefik` service block from `docker-compose.yml`.
2.  Connect `tldraw-sync` to your existing Traefik network:
    ```yaml
    networks:
      default:
        external: true
        name: proxy  # replace with your Traefik network name
    ```

### If you are starting fresh:
1.  Keep the `traefik` service in the compose file.
2.  Ensure ports 80 and 443 are open on your firewall.

## Step 5: Start the Service

The `docker-compose.yml` is configured to pull the pre-built image from the **GitHub Container Registry (GHCR)**:

```bash
# Pull the latest image and start
docker compose pull
docker compose up -d
```

The image is published at:
```
ghcr.io/larkly/nextcloud-tldraw:latest
```

Available tags:

| Tag | Description |
|-----|-------------|
| `latest` | Most recent build from the `main` branch |
| `v0.0.1`, `v0.1.0`, … | Pinned release versions |

To pin to a specific release, edit `docker-compose.yml`:
```yaml
image: ghcr.io/larkly/nextcloud-tldraw:v0.0.1
```

### Building from Source (optional)

If you want to build the image yourself instead of pulling from GHCR, uncomment the `build:` block in `docker-compose.yml` and comment out the `image:` line, then run:

```bash
docker compose build
docker compose up -d
```

## Step 6: Verify the Service

1.  Check the logs:
    ```bash
    docker compose logs -f tldraw-sync
    ```
    You should see: `Collab Server running on port 3000`

2.  Test the health endpoint:
    ```bash
    curl https://tldraw.example.com/health
    # Expected: {"status":"ok"}
    ```

## Step 7: Configure Nextcloud

1.  Log in to your Nextcloud instance as an admin.
2.  Go to **Administration Settings > tldraw**.
3.  Set **Collab Server URL** to `https://tldraw.example.com`.
4.  Set **JWT Secret** to the same value as `JWT_SECRET_KEY` in your `.env` file.
5.  Click **Save**.
