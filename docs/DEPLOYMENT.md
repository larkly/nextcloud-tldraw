# Deployment Guide

This guide covers the deployment of the **Collab Server**, which is the Node.js backend required for real-time collaboration.

## Prerequisites

-   **Docker** and **Docker Compose** installed on the server.
-   **Traefik** (recommended) or another reverse proxy for SSL termination.
-   A **dedicated domain** for the collab server (e.g., `tldraw.example.com`).

## Step 1: Prepare the Environment

1.  Create a directory for the service (e.g., `/opt/tldraw-sync`).
2.  Copy the `docker-compose.yml` and `.env.example` files from the repository to this directory.
3.  Rename `.env.example` to `.env`.

## Step 2: Create a Service User

The Collab Server needs a user account to read/write files via WebDAV on behalf of other users. For security, create a dedicated "bot" account.

1.  **Create User:** In Nextcloud, go to **Users** and create a new user (e.g., `tldraw-bot`).
2.  **Grant Permissions:** Add this user to the `admin` group.
    *   *Why?* Only admin users can access the `/remote.php/dav/files/<target_user>/` endpoint to save files for other people.
3.  **Generate App Password:**
    *   Log in as `tldraw-bot`.
    *   Go to **Settings > Security**.
    *   Scroll to **Devices & sessions**.
    *   Create a new App Password named "Collab Server".
    *   Copy this password. **Do not use the login password.**

## Step 3: Configure Environment

Edit the `.env` file with the bot's credentials:

```bash
# Security: Generate a secure 32-byte hex string
JWT_SECRET_KEY=... 

# Nextcloud Connection
NC_URL=https://your-nextcloud.com

# Service User Credentials (from Step 2)
NC_USER=tldraw-bot
NC_PASS=your-generated-app-password

# Traefik Domain
TLDRAW_HOST=tldraw.example.com
```

## Step 3: Traefik Configuration

The provided `docker-compose.yml` includes labels for Traefik v3.

### If you already have Traefik running:
1.  Remove the `traefik` service block from `docker-compose.yml`.
2.  Ensure the `tldraw-sync` service is connected to your existing Traefik network (e.g., `web` or `proxy`).
    ```yaml
    networks:
      default:
        external: true
        name: proxy  # Change to your Traefik network name
    ```

### If you are starting fresh:
1.  Keep the `traefik` service in the compose file.
2.  Ensure ports 80 and 443 are open on your firewall.

## Step 4: Start the Service

```bash
docker-compose up -d
```

## Step 5: Verification

1.  Check logs:
    ```bash
    docker-compose logs -f tldraw-sync
    ```
2.  Verify the Health Check:
    Visit `https://tldraw.example.com/health` in your browser. You should see `{"status":"ok"}`.

## Step 6: Nextcloud Configuration

1.  Log in to your Nextcloud instance as an admin.
2.  Go to **Administration Settings > tldraw**.
3.  Set **Collab Server URL** to `https://tldraw.example.com`.
4.  Set **JWT Secret** to the value of `JWT_SECRET_KEY` from your `.env` file.
