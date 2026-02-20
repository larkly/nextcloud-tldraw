<?php
declare(strict_types=1);

namespace OCA\Tldraw\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class AdminController extends Controller {
    private $config;

    public function __construct(string $appName, IRequest $request, IConfig $config) {
        parent::__construct($appName, $request);
        $this->config = $config;
    }

    /**
     * @AdminRequired
     * @NoCSRFRequired
     */
    public function getSettings(): DataResponse {
        return new DataResponse([
            'collabServerUrl' => $this->config->getAppValue('tldraw', 'collab_server_url', ''),
            'jwtSecretIsSet' => !empty($this->config->getAppValue('tldraw', 'jwt_secret', '')),
        ]);
    }

    /**
     * @AdminRequired
     */
    public function saveSettings(string $collabServerUrl, string $jwtSecret): DataResponse {
        $this->config->setAppValue('tldraw', 'collab_server_url', $collabServerUrl);
        if (!empty($jwtSecret)) {
            $this->config->setAppValue('tldraw', 'jwt_secret', $jwtSecret);
        }
        return new DataResponse(['status' => 'success']);
    }
}
