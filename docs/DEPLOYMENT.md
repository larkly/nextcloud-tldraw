# Deployment Guide

This guide covers deploying the **Collab Server** — the Node.js backend required for real-time collaboration. The Nextcloud App installation is covered in the [README](../README.md#3-install-the-nextcloud-app).

## Prerequisites

- **Docker** and **Docker Compose v2** (`docker compose`) on the server.
- **Traefik** (recommended) or another reverse proxy for SSL termination.
- A **dedicated domain** pointing to your server (e.g. `tldraw.example.com`).
- Access to your Nextcloud instance as an administrator.

---

## Step 1: Get the Files

Clone the repository (or download a release archive) onto the server where Docker will run:

```bash
git clone https://github.com/larkly/nextcloud-tldraw.git
cd nextcloud-tldraw
```

If you prefer to keep the collab server files separate from the source code, copy just the two files you need:

```bash
mkdir -p /opt/tldraw-sync
cp docker-compose.yml .env.example /opt/tldraw-sync/
cd /opt/tldraw-sync
```

---

## Step 2: Create a Service User in Nextcloud

The Collab Server reads and writes `.tldr` files via WebDAV on behalf of all users. It must authenticate as a dedicated bot account — never use your personal admin account.

1. In Nextcloud, go to **Users** and create a new user (e.g. `tldraw-bot`).
2. Add that user to the `admin` group.
   > **Why admin?** The WebDAV endpoint `/remote.php/dav/files/<user>/` only allows the owner or an admin to access it. The bot needs admin access to write files on behalf of other users.
3. Log in as `tldraw-bot`, go to **Settings > Security > Devices & sessions**, and create a new **App Password** named `Collab Server`.
   > **Important:** Copy this password immediately — it is only shown once. Do not use the login password.

---

## Step 3: Configure the Environment

Copy `.env.example` to `.env` and fill in your values:

```bash
cp .env.example .env
```

```bash
# Generate with: openssl rand -hex 32
# Must match the 'JWT Secret' saved in Nextcloud Admin Settings.
JWT_SECRET_KEY=paste_your_generated_secret_here

# Base URL of your Nextcloud instance — no trailing slash
NC_URL=https://nextcloud.example.com

# Bot account credentials from Step 2
NC_USER=tldraw-bot
NC_PASS=your-app-password-here

# Domain where this collab server will be reachable
TLDRAW_HOST=tldraw.example.com

# Email for Let's Encrypt certificate expiry notifications
ACME_EMAIL=admin@example.com
```

---

## Step 4: Configure Traefik

The `docker-compose.yml` ships with Traefik v3 labels on the `tldraw-sync` service and an optional Traefik service for new deployments.

### If you already have Traefik running

Remove the `traefik` service block from `docker-compose.yml`, then connect `tldraw-sync` to your existing Traefik network by replacing the `networks` section at the bottom of the file:

```yaml
networks:
  default:
    external: true
    name: proxy   # change to your Traefik network name
```

### If you are starting fresh

Keep the `traefik` service in the compose file and ensure ports 80 and 443 are open on your server's firewall. Traefik will automatically obtain a Let's Encrypt certificate for `TLDRAW_HOST` on first startup.

---

## Step 5: Start the Service

The `docker-compose.yml` is pre-configured to pull the image from the **GitHub Container Registry**:

```bash
docker compose pull
docker compose up -d
```

> **First startup note:** If Traefik is obtaining a new Let's Encrypt certificate it may take up to a minute before HTTPS is available.

### Container image

| Registry | Image |
|---|---|
| GHCR | `ghcr.io/larkly/nextcloud-tldraw` |

Available tags:

| Tag | Description |
|---|---|
| `latest` | Most recent build from the `main` branch |
| `v0.0.1`, `v0.1.0`, … | Pinned release versions — recommended for production |

To pin to a specific release, edit the `image:` line in `docker-compose.yml`:
```yaml
image: ghcr.io/larkly/nextcloud-tldraw:v0.0.1
```

### Building from source (optional)

To build the image locally instead of pulling from GHCR, open `docker-compose.yml`, comment out the `image:` line, and uncomment the `build:` block, then run:

```bash
docker compose build
docker compose up -d
```

---

## Step 6: Verify the Service

```bash
# Check the container started cleanly
docker compose logs tldraw-sync
# Expected output includes: "Collab Server running on port 3000"

# Test the health endpoint
curl https://tldraw.example.com/health
# Expected: {"status":"ok"}
```

---

## Step 7: Configure Nextcloud

1. Log in to Nextcloud as an admin.
2. Go to **Administration Settings > tldraw**.
3. Set **Collab Server URL** to `https://tldraw.example.com`.
4. Set **JWT Secret** to the exact same value as `JWT_SECRET_KEY` in your `.env` file.
5. Click **Save**.

The two components are now connected. Open a `.tldr` file in Nextcloud Files to confirm the editor loads.
