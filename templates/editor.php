<?php
/**
 * Nextcloud tldraw
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 the nextcloud-tldraw contributors
 */
/** @var array $_ */
use OCP\Util;

// Load the compiled frontend script
Util::addScript('tldraw', 'tldraw-main');
Util::addStyle('tldraw', 'style'); // If we have CSS
?>

<div id="tldraw-root"
     style="position: absolute; top: 0; left: 0; right: 0; bottom: 0;"
     data-file-id="<?php p($_['fileId']); ?>"
     data-file-name="<?php p($_['fileName']); ?>"
     data-can-write="<?php p($_['canWrite'] ? 'true' : 'false'); ?>"
     data-ws-server-url="<?php p($_['wsServerUrl']); ?>"
     data-token-url="<?php p($_['tokenUrl']); ?>">
</div>
