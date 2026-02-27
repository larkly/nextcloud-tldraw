<?php
/**
 * Nextcloud tldraw
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 the nextcloud-tldraw contributors
 */
declare(strict_types=1);

namespace OCA\Tldraw\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use OCP\Viewer\Event\LoadViewer;

class LoadViewerListener implements IEventListener {
    public function handle(Event $event): void {
        if (!($event instanceof LoadViewer)) {
            return;
        }
        // Load our main frontend script when the Viewer app is active
        Util::addScript('tldraw', 'tldraw-main');
    }
}
