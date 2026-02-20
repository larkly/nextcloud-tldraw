<?php
/** @var array $_ */
script('core', 'oc');
?>

<div id="tldraw-admin-settings">
    <div class="section">
        <h2><?php p($l->t('tldraw Settings')); ?></h2>

        <p class="settings-hint">
            <?php p($l->t('Configure the connection between Nextcloud and your tldraw Collab Server.')); ?>
        </p>

        <div id="tldraw-admin-msg" style="display:none; padding: 8px 12px; border-radius: 4px; margin-bottom: 16px;"></div>

        <p>
            <label for="tldraw-collab-server-url"><?php p($l->t('Collab Server URL')); ?></label><br>
            <input type="url"
                   id="tldraw-collab-server-url"
                   name="collabServerUrl"
                   placeholder="https://tldraw.example.com"
                   style="width: 360px;"
                   class="tldraw-setting">
            <span class="hint"><?php p($l->t('The WebSocket/HTTP endpoint of the tldraw collab server (e.g. wss://tldraw.example.com).')); ?></span>
        </p>

        <p>
            <label for="tldraw-jwt-secret"><?php p($l->t('JWT Secret')); ?></label><br>
            <input type="password"
                   id="tldraw-jwt-secret"
                   name="jwtSecret"
                   placeholder="<?php p($l->t('Enter new secret to change')); ?>"
                   autocomplete="new-password"
                   style="width: 360px;"
                   class="tldraw-setting">
            <span class="hint"><?php p($l->t('Must match the JWT_SECRET_KEY set in the collab server .env file. Leave blank to keep the current value.')); ?></span>
        </p>

        <p id="tldraw-jwt-status"></p>

        <button id="tldraw-save-btn" class="button"><?php p($l->t('Save')); ?></button>
    </div>
</div>

<script>
(function () {
    'use strict';

    var saveUrl  = OC.generateUrl('/apps/tldraw/admin');
    var loadUrl  = OC.generateUrl('/apps/tldraw/admin');
    var msgEl    = document.getElementById('tldraw-admin-msg');
    var statusEl = document.getElementById('tldraw-jwt-status');

    function showMsg(text, isError) {
        msgEl.textContent = text;
        msgEl.style.display = 'block';
        msgEl.style.background = isError ? '#f8d7da' : '#d4edda';
        msgEl.style.color      = isError ? '#721c24' : '#155724';
        setTimeout(function () { msgEl.style.display = 'none'; }, 4000);
    }

    // Load current settings on page load
    fetch(loadUrl, { headers: { 'Accept': 'application/json',
                                'requesttoken': OC.requestToken } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            document.getElementById('tldraw-collab-server-url').value = data.collabServerUrl || '';
            if (data.jwtSecretIsSet) {
                statusEl.textContent = '<?php p($l->t('A JWT secret is currently configured.')); ?>';
                statusEl.style.color = '#155724';
            } else {
                statusEl.textContent = '<?php p($l->t('No JWT secret configured. Please set one below.')); ?>';
                statusEl.style.color = '#856404';
            }
        })
        .catch(function () {
            showMsg('<?php p($l->t('Failed to load current settings.')); ?>', true);
        });

    // Save settings
    document.getElementById('tldraw-save-btn').addEventListener('click', function () {
        var collabServerUrl = document.getElementById('tldraw-collab-server-url').value.trim();
        var jwtSecret       = document.getElementById('tldraw-jwt-secret').value;

        fetch(saveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({ collabServerUrl: collabServerUrl, jwtSecret: jwtSecret })
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function () {
            document.getElementById('tldraw-jwt-secret').value = '';
            showMsg('<?php p($l->t('Settings saved.')); ?>', false);
            if (jwtSecret) {
                statusEl.textContent = '<?php p($l->t('A JWT secret is currently configured.')); ?>';
                statusEl.style.color = '#155724';
            }
        })
        .catch(function () {
            showMsg('<?php p($l->t('Failed to save settings. Make sure you are an administrator.')); ?>', true);
        });
    });
}());
</script>
