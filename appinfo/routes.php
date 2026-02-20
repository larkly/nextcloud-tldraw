<?php
return [
    'routes' => [
        // Serves the editor page
        ['name' => 'tldraw#edit', 'url' => '/edit/{fileId}', 'verb' => 'GET'],
        // Issues a short-lived JWT for the WebSocket connection
        ['name' => 'tldraw#token', 'url' => '/token/{fileId}', 'verb' => 'GET'],
        // Admin settings
        ['name' => 'admin#getSettings', 'url' => '/admin', 'verb' => 'GET'],
        ['name' => 'admin#saveSettings', 'url' => '/admin', 'verb' => 'POST'],
    ],
];
