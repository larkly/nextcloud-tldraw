<?php
/**
 * Nextcloud tldraw
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 the nextcloud-tldraw contributors
 */
declare(strict_types=1);

namespace OCA\Tldraw\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Server-to-server file I/O callback endpoints.
 *
 * These endpoints are called by the collab server (not the browser) to read
 * and write .tldr files and uploaded image assets on behalf of users.
 *
 * Authentication is via a storage token (HS256 JWT) issued alongside the
 * WebSocket token by TldrawController::token(). No Nextcloud user session
 * is required — the collab server never holds Nextcloud credentials.
 *
 * This follows the same pattern as Nextcloud's WOPI integration for Collabora
 * and ONLYOFFICE: the external server calls back to PHP with a file-scoped
 * token and PHP performs the I/O using IRootFolder.
 */
class FileController extends Controller {
    private const ASSET_DIR = '.tldraw-assets';

    private IRootFolder $rootFolder;
    private IConfig $config;

    public function __construct(
        string $appName,
        IRequest $request,
        IRootFolder $rootFolder,
        IConfig $config
    ) {
        parent::__construct($appName, $request);
        $this->rootFolder = $rootFolder;
        $this->config = $config;
    }

    /**
     * Read the raw content of a .tldr file.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function read(int $fileId): Http\Response {
        $payload = $this->requireStorageToken(read: true);
        if ($payload instanceof JSONResponse) return $payload;

        if ((int)$payload['fileId'] !== $fileId) {
            return new JSONResponse(['error' => 'Token/file mismatch'], 403);
        }

        try {
            $file = $this->getFileNode((string)$payload['ownerId'], (string)$payload['filePath']);
            $content = $file->getContent();
            $response = new Http\DataDisplayResponse($content, 200, [
                'Content-Type' => 'application/json',
            ]);
            return $response;
        } catch (NotFoundException $e) {
            // New file — return empty content so the collab server starts a blank room
            return new Http\DataDisplayResponse('', 200, ['Content-Type' => 'application/json']);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Read failed'], 500);
        }
    }

    /**
     * Overwrite the content of a .tldr file.
     * Requires canWrite: true in the storage token.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function save(int $fileId): JSONResponse {
        $payload = $this->requireStorageToken(read: false);
        if ($payload instanceof JSONResponse) return $payload;

        if ((int)$payload['fileId'] !== $fileId) {
            return new JSONResponse(['error' => 'Token/file mismatch'], 403);
        }

        $body = $this->request->getContent();
        if (empty($body)) {
            return new JSONResponse(['error' => 'Empty body'], 400);
        }

        try {
            $ownerFolder = $this->rootFolder->getUserFolder((string)$payload['ownerId']);
            try {
                $file = $ownerFolder->get((string)$payload['filePath']);
                $file->putContent($body);
            } catch (NotFoundException $e) {
                // File doesn't exist yet — create it
                $ownerFolder->newFile((string)$payload['filePath'], $body);
            }
            return new JSONResponse(['status' => 'ok']);
        } catch (NotPermittedException $e) {
            return new JSONResponse(['error' => 'Not permitted'], 403);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Save failed'], 500);
        }
    }

    /**
     * Upload an image asset. Returns {assetKey} used to retrieve it later.
     * Requires canWrite: true in the storage token.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function uploadAsset(int $fileId): JSONResponse {
        $payload = $this->requireStorageToken(read: false);
        if ($payload instanceof JSONResponse) return $payload;

        if ((int)$payload['fileId'] !== $fileId) {
            return new JSONResponse(['error' => 'Token/file mismatch'], 403);
        }

        // Collab server sends the file as raw body with Content-Type header
        $mimeType = $this->request->getHeader('Content-Type') ?: 'application/octet-stream';
        $data = $this->request->getContent();

        if (empty($data)) {
            return new JSONResponse(['error' => 'No data'], 400);
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        // Strip parameters from MIME (e.g. "image/png; charset=binary")
        $baseMime = explode(';', $mimeType)[0];
        $baseMime = trim($baseMime);
        if (!in_array($baseMime, $allowedMimes, true)) {
            return new JSONResponse(['error' => 'Unsupported file type'], 400);
        }

        $ext = match($baseMime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'bin',
        };

        $ownerId = (string)$payload['ownerId'];
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $assetKey = $ownerId . '/' . $filename;

        try {
            $ownerFolder = $this->rootFolder->getUserFolder($ownerId);
            if (!$ownerFolder->nodeExists(self::ASSET_DIR)) {
                $ownerFolder->newFolder(self::ASSET_DIR);
            }
            $assetFolder = $ownerFolder->get(self::ASSET_DIR);
            $assetFolder->newFile($filename, $data);

            return new JSONResponse([
                'assetKey' => base64_encode($assetKey),
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Upload failed'], 500);
        }
    }

    /**
     * Serve an image asset. The assetKey is base64(ownerId/filename).
     * Requires a valid storage token (read-only sufficient).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function serveAsset(string $assetKey): Http\Response {
        $payload = $this->requireStorageToken(read: true);
        if ($payload instanceof JSONResponse) return $payload;

        $decoded = base64_decode($assetKey, strict: true);
        if ($decoded === false || !str_contains($decoded, '/')) {
            return new JSONResponse(['error' => 'Invalid asset key'], 400);
        }

        [$ownerId, $filename] = explode('/', $decoded, 2);

        // Path traversal guard
        if (str_contains($filename, '/') || str_contains($filename, '..') ||
            str_contains($ownerId, '/') || str_contains($ownerId, '..')) {
            return new JSONResponse(['error' => 'Invalid path'], 400);
        }

        try {
            $ownerFolder = $this->rootFolder->getUserFolder($ownerId);
            $file = $ownerFolder->get(self::ASSET_DIR . '/' . $filename);
            $content = $file->getContent();

            $mime = match(true) {
                str_ends_with($filename, '.png')            => 'image/png',
                str_ends_with($filename, '.jpg')            => 'image/jpeg',
                str_ends_with($filename, '.gif')            => 'image/gif',
                str_ends_with($filename, '.webp')           => 'image/webp',
                default                                      => 'application/octet-stream',
            };

            return new Http\DataDisplayResponse($content, 200, ['Content-Type' => $mime]);
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'Not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Serve failed'], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extract and validate the storage token from the Authorization: Bearer header.
     * Returns the decoded payload array on success, or a JSONResponse on failure.
     *
     * @param bool $read  true = read access sufficient; false = canWrite required
     * @return array|JSONResponse
     */
    private function requireStorageToken(bool $read): array|JSONResponse {
        $authHeader = $this->request->getHeader('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return new JSONResponse(['error' => 'Missing token'], 401);
        }

        $token = substr($authHeader, 7);
        $payload = $this->verifyStorageToken($token);
        if ($payload === null) {
            return new JSONResponse(['error' => 'Invalid or expired token'], 403);
        }

        if (($payload['type'] ?? '') !== 'storage') {
            return new JSONResponse(['error' => 'Wrong token type'], 403);
        }

        if (!$read && empty($payload['canWrite'])) {
            return new JSONResponse(['error' => 'Read-only token'], 403);
        }

        return $payload;
    }

    /**
     * Verify an HS256 storage token. Returns decoded payload or null if invalid.
     */
    private function verifyStorageToken(string $token): ?array {
        $secret = $this->config->getAppValue('tldraw', 'jwt_secret', '');
        if (empty($secret)) return null;

        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $expected = rtrim(strtr(base64_encode(hash_hmac(
            'sha256',
            $headerB64 . '.' . $payloadB64,
            $secret,
            true
        )), '+/', '-_'), '=');

        if (!hash_equals($expected, $signatureB64)) return null;

        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
        if (!is_array($payload)) return null;

        if (isset($payload['exp']) && time() > $payload['exp']) return null;

        return $payload;
    }

    /**
     * Get a file node by owner ID and relative path.
     *
     * @throws NotFoundException
     */
    private function getFileNode(string $ownerId, string $filePath): \OCP\Files\File {
        $ownerFolder = $this->rootFolder->getUserFolder($ownerId);
        $node = $ownerFolder->get($filePath);
        if (!($node instanceof \OCP\Files\File)) {
            throw new NotFoundException("Not a file: $filePath");
        }
        return $node;
    }
}
