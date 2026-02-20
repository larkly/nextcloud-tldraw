<?php
declare(strict_types=1);

namespace OCA\Tldraw\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class TldrawController extends Controller {
    private $rootFolder;
    private $userId;
    private $config;

    public function __construct(
        string $appName,
        IRequest $request,
        IRootFolder $rootFolder,
        IUserSession $userSession,
        IConfig $config
    ) {
        parent::__construct($appName, $request);
        $this->rootFolder = $rootFolder;
        $this->userId = $userSession->getUser() ? $userSession->getUser()->getUID() : null;
        $this->config = $config;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function edit(int $fileId): TemplateResponse|NotFoundResponse {
        try {
            $userFolder = $this->rootFolder->getUserFolder($this->userId);
            $files = $userFolder->getById($fileId);
        } catch (NotFoundException $e) {
            return new NotFoundResponse();
        }

        if (empty($files)) {
            return new NotFoundResponse();
        }

        $node = $files[0];
        
        // Check read permission
        if (!$node->isReadable()) {
            return new NotFoundResponse(); // Or Forbidden
        }

        $canWrite = $node->isUpdateable();
        $fileName = $node->getName();
        
        // We do NOT embed the token here. The frontend will fetch it via fetch().
        return new TemplateResponse('tldraw', 'editor', [
            'fileId' => $fileId,
            'fileName' => $fileName,
            'canWrite' => $canWrite,
            'wsServerUrl' => $this->config->getAppValue('tldraw', 'collab_server_url', ''),
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function token(int $fileId): JSONResponse {
        try {
            $userFolder = $this->rootFolder->getUserFolder($this->userId);
            $files = $userFolder->getById($fileId);
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'File not found'], 404);
        }

        if (empty($files)) {
            return new JSONResponse(['error' => 'File not found'], 404);
        }

        $node = $files[0];
        if (!$node->isReadable()) {
            return new JSONResponse(['error' => 'Access denied'], 403);
        }

        $jwtSecret = $this->config->getAppValue('tldraw', 'jwt_secret', '');
        if (empty($jwtSecret)) {
            return new JSONResponse(['error' => 'Server not configured'], 500);
        }

        // Deterministic room token based on file ID
        // This prevents users from guessing the room ID just by knowing the file ID integer
        $roomToken = hash_hmac('sha256', 'room:' . $fileId, $jwtSecret);

        $owner = $node->getOwner();
        $ownerId = $owner->getUID();
        $ownerFolder = $this->rootFolder->getUserFolder($ownerId);
        $filePath = $ownerFolder->getRelativePath($node);

        $payload = [
            'fileId' => $fileId,
            'roomToken' => $roomToken,
            'userId' => $this->userId,
            'ownerId' => $ownerId,
            'filePath' => $filePath,
            'canWrite' => $node->isUpdateable(),
            'exp' => time() + 43200, // 12 hours token validity
        ];

        $jwt = $this->generateJwt($payload, $jwtSecret);

        return new JSONResponse([
            'token' => $jwt,
            'wsUrl' => $this->config->getAppValue('tldraw', 'collab_server_url', ''),
        ]);
    }

    private function generateJwt(array $payload, string $secret): string {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode($payload);

        $base64UrlHeader = $this->base64UrlEncode($header);
        $base64UrlPayload = $this->base64UrlEncode($payload);

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = $this->base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    private function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
