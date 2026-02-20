# Nextcloud tldraw App

> [!WARNING]
> **This project is currently in early development and is not ready for production use.**
> We are actively working on it, and pull requests are accepted and greatly appreciated!

```
  _   _           _       _                 _
 | \ | | _____  _| |_ ___| | ___  _   _  __| |
 |  \| |/ _ \ \/ / __/ __| |/ _ \| | | |/ _` |
 | |\  |  __/>  <| || (__| | (_) | |_| | (_| |
 |_| \_|\___/_/\_\\__\___|_|\___/ \__,_|\__,_|

      _   _     _
     | |_| | __| |_ __ __ ___      __
     | __| |/ _` | '__/ _` \ \ /\ / /
     | |_| | (_| | | | (_| |\ V  V /
      \__|_|\__,_|_|  \__,_| \_/\_/
```

This application integrates [tldraw](https://tldraw.dev) into Nextcloud, allowing users to create, edit, and collaborate on whiteboards in real-time. Drawings are stored natively as `.tldr` files within your Nextcloud instance.

## Features

- **Real-Time Collaboration:** Multiple users can edit the same drawing simultaneously.
- **Native Integration:** `.tldr` files appear in Nextcloud Files with custom icons and previews.
- **Secure Architecture:** Uses short-lived JWTs (60s) and authenticated uploads.
- **Dockerized Backend:** Production-ready `docker-compose` setup with Traefik support.

## Architecture

The system consists of two main components:

1.  **Nextcloud App (PHP/JS):**
    -   Handles file management, user authentication, and rendering the editor.
    -   Located in this repository.
    -   Built with PHP 8.2+ and React (Vite).

2.  **Collab Server (Node.js/Docker):**
    -   Manages real-time WebSocket connections.
    -   Handles asset uploads securely.
    -   Syncs drawing state to Nextcloud via WebDAV.
    -   Located in `collab-server/`.

## Documentation

-   [**User Guide:**](docs/USAGE.md) How to create, edit, and collaborate on drawings.
-   [**Administration Guide:**](docs/ADMINISTRATION.md) Configuration, updates, and troubleshooting.
-   [**Deployment Guide:**](docs/DEPLOYMENT.md) Setting up the Docker backend.
-   [**Architecture:**](docs/ARCHITECTURE.md) Detailed system design.


## Quick Start Deployment

To deploy this application, you need:
1.  A Nextcloud 28+ server (PHP 8.2+).
2.  A server running Docker (for the Collab backend).
3.  A domain name for the Collab server (e.g., `tldraw.example.com`).

### 1. Deploy the Collab Server (Docker)

1.  **Create Service User:**
    -   Create a new Nextcloud user (e.g., `tldraw-bot`).
    -   Add this user to the `admin` group.
    -   See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for details on generating a secure App Password.

2.  **Configure Environment:**
    Copy `.env.example` to `.env` and fill in your values:
    ```bash
    cp .env.example .env
    nano .env
    ```
    -   `JWT_SECRET_KEY`: A long random hex string (32 bytes recommended).
    -   `NC_URL`: Your Nextcloud instance URL.
    -   `NC_USER` / `NC_PASS`: Credentials for the Service User created in Step 1.
    -   `TLDRAW_HOST`: The domain for the collab server.

2.  Start the service using Docker Compose:
    ```bash
    docker-compose up -d
    ```
    This will start the Node.js server and Traefik (if enabled) to handle SSL.

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for advanced Traefik configurations.

### 2. Install the Nextcloud App

You can package the app for any Nextcloud server using the provided script:

1.  Run the build script:
    ```bash
    bash scripts/make-release.sh
    ```
    This creates a `nextcloud-tldraw-<version>.tar.gz` archive (e.g., `nextcloud-tldraw-0.0.1.tar.gz`).

2.  Extract it into your Nextcloud `apps/` directory:
    ```bash
    tar -xzf nextcloud-tldraw-<version>.tar.gz -C /var/www/nextcloud/apps/
    ```

3.  Enable the app:
    ```bash
    php occ app:enable tldraw
    ```

4.  Configure Settings:
    -   Go to **Administration Settings > tldraw**.
    -   **Collab Server URL:** Enter your Collab Server domain (e.g., `https://tldraw.example.com`).
    -   **JWT Secret:** Enter the *same* secret key from your `.env` file.

## Usage

1.  Navigate to the **Files** app in Nextcloud.
2.  Click the **+ New** button and select **New tldraw drawing**.
3.  Name your file (e.g., `brainstorming.tldr`).
4.  Click the file to open the editor.
5.  Share the file with other users to collaborate in real-time!

## Development

To work on the app locally:

1.  **Frontend:**
    ```bash
    npm install
    npm run dev  # Watches for changes and rebuilds
    ```

2.  **Backend (PHP):**
    -   Symlink this directory to your local Nextcloud apps folder.
    -   Enable debug mode in Nextcloud to disable caching.

3.  **Collab Server:**
    ```bash
    cd collab-server
    npm install
    npm run dev
    ```

## Security

-   **Tokens:** WebSocket connections use short-lived JWTs (60s expiry) to prevent replay attacks.
-   **Uploads:** Asset uploads require a valid JWT in the `Authorization` header.
-   **Permissions:** Write access is enforced server-side based on the JWT `canWrite` claim.

## License

AGPL-3.0
