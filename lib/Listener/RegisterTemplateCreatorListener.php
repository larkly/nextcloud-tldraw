<?php
declare(strict_types=1);

namespace OCA\Tldraw\Listener;

use OCA\Tldraw\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Template\RegisterTemplateCreatorEvent;
use OCP\Files\Template\TemplateFileCreator;

class RegisterTemplateCreatorListener implements IEventListener {
    public function handle(Event $event): void {
        if (!($event instanceof RegisterTemplateCreatorEvent)) {
            return;
        }

        $event->registerTemplateFileCreator(function () {
            $fileCreator = new TemplateFileCreator(Application::APP_ID, 'New tldraw drawing', '.tldr');
            $fileCreator->setIconSvgInline(file_get_contents(__DIR__ . '/../../img/filetypes/tldr.svg'));
            $fileCreator->setMimetypes(['application/x-tldraw']);
            return $fileCreator;
        });
    }
}
