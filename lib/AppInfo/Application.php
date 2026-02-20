<?php
declare(strict_types=1);

namespace OCA\Tldraw\AppInfo;

use OCA\Tldraw\Listener\LoadViewerListener;
use OCA\Tldraw\Listener\RegisterTemplateCreatorListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IServerContext;
use OCP\Viewer\Event\LoadViewer;
use OCP\Files\Template\RegisterTemplateCreatorEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'tldraw';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register the "New tldraw drawing" template creator
        $context->registerEventListener(RegisterTemplateCreatorEvent::class, RegisterTemplateCreatorListener::class);
        // Register the Viewer integration (if Viewer app is present)
        $context->registerEventListener(LoadViewer::class, LoadViewerListener::class);
    }

    public function boot(IServerContext $context): void {
    }
}
