<?php
/**
 * Nextcloud tldraw
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 the nextcloud-tldraw contributors
 */
return [
    'routes' => [
        // Serves the editor page
        ['name' => 'tldraw#edit', 'url' => '/edit/{fileId}', 'verb' => 'GET'],
        // Issues a short-lived JWT for the WebSocket connection
        ['name' => 'tldraw#token', 'url' => '/token/{fileId}', 'verb' => 'GET'],
        // Collab server file I/O callbacks (authenticated via storage token, no user session needed)
        ['name' => 'file#read',        'url' => '/file/{fileId}',       'verb' => 'GET'],
        ['name' => 'file#save',        'url' => '/file/{fileId}',       'verb' => 'PUT'],
        ['name' => 'file#uploadAsset', 'url' => '/file/{fileId}/asset', 'verb' => 'POST'],
        ['name' => 'file#serveAsset',  'url' => '/asset/{assetKey}',    'verb' => 'GET'],
        // Admin settings
        ['name' => 'admin#getSettings', 'url' => '/admin', 'verb' => 'GET'],
        ['name' => 'admin#saveSettings', 'url' => '/admin', 'verb' => 'POST'],
    ],
];
