<?php
declare(strict_types=1);

namespace OCA\Tldraw\Settings;

use OCA\Tldraw\Controller\AdminController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    public function getForm(): TemplateResponse {
        return new TemplateResponse('tldraw', 'admin');
    }

    public function getSection(): string {
        return 'tldraw';
    }

    public function getPriority(): int {
        return 10;
    }
}
