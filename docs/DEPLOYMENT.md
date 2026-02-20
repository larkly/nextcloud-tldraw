# Deployment Guide

This guide covers deploying the **Collab Server** — the Node.js backend required for real-time collaboration. The Nextcloud App installation is covered in the [README](../README.md#3-install-the-nextcloud-app).

## Prerequisites

- **Docker** and **Docker Compose v2** (`docker compose`) on the server.
- **Traefik** (recommended) or another reverse proxy for SSL termination.
- A **dedicated domain** pointing to your server (e.g. `tldraw.example.com`).
- Access to your Nextcloud instance as an administrator.

> **No service account needed.** The collab server no longer requires a Nextcloud user account. File I/O is handled via authenticated callbacks to the Nextcloud PHP app (see [Architecture](ARCHITECTURE.md)).

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

## Step 2: Configure the Environment

Copy `.env.example` to `.env` and fill in your values:

```bash
cp .env.example .env
```

```bash
# Generate with: openssl rand -hex 32
# Must match the 'JWT Secret' saved in Nextcloud Admin Settings.
JWT_SECRET_KEY=paste_your_generated_secret_here

# Base URL of your Nextcloud instance — no trailing slash.
# The collab server calls back to this URL for file I/O.
NC_URL=https://nextcloud.example.com

# Domain where this collab server will be reachable
TLDRAW_HOST=tldraw.example.com

# Email for Let's Encrypt certificate expiry notifications
ACME_EMAIL=admin@example.com
```

---

## Step 3: Configure Traefik

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

## Step 4: Start the Service

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

## Step 5: Verify the Service

```bash
# Check the container started cleanly
docker compose logs tldraw-sync
# Expected output includes: "Collab Server running on port 3000"

# Test the health endpoint
curl https://tldraw.example.com/health
# Expected: {"status":"ok"}
```

---

## Step 6: Configure Nextcloud

1. Log in to Nextcloud as an admin.
2. Go to **Administration Settings > tldraw**.
3. Set **Collab Server URL** to `https://tldraw.example.com`.
4. Set **JWT Secret** to the exact same value as `JWT_SECRET_KEY` in your `.env` file.
5. Click **Save**.

The two components are now connected. Open a `.tldr` file in Nextcloud Files to confirm the editor loads.
