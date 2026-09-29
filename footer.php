    </main>
</div>
<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    var overlay = document.getElementById('sidebar-overlay');
    if (overlay) overlay.classList.toggle('show');
}
function renderBarcodes() {
    document.querySelectorAll('.barcode-tiny').forEach(function(el) {
        try { JsBarcode(el, el.getAttribute('data-barcode'), { format: 'CODE128', width: 1, height: 24, displayValue: false, margin: 2, background: 'transparent' }); } catch(e) {}
    });
    document.querySelectorAll('.barcode-preview').forEach(function(el) {
        var input = el.parentNode.querySelector('input[name="barcode"]');
        if (input && input.value) {
            try { JsBarcode(el, input.value, { format: 'CODE128', width: 2, height: 50, displayValue: true, margin: 4, background: 'transparent' }); } catch(e) {}
        }
    });
}
function generateBarcode(btn) {
    var input = btn.parentNode.querySelector('input[name="barcode"]');
    if (!input) return;
    var code = '';
    for (var i = 0; i < 12; i++) code += Math.floor(Math.random() * 10);
    input.value = code;
    var preview = btn.parentNode.parentNode.querySelector('.barcode-preview');
    if (preview) {
        preview.style.display = 'block';
        try { JsBarcode(preview, code, { format: 'CODE128', width: 2, height: 50, displayValue: true, margin: 4, background: 'transparent' }); } catch(e) {}
    }
}
document.addEventListener('DOMContentLoaded', function() {
    renderBarcodes();
    document.querySelectorAll('input[name="barcode"]').forEach(function(inp) {
        inp.addEventListener('input', function() {
            var preview = this.parentNode.parentNode.querySelector('.barcode-preview');
            if (preview) {
                if (this.value) {
                    preview.style.display = 'block';
                    try { JsBarcode(preview, this.value, { format: 'CODE128', width: 2, height: 50, displayValue: true, margin: 4, background: 'transparent' }); } catch(e) { preview.style.display = 'none'; }
                } else {
                    preview.style.display = 'none';
                }
            }
        });
    });
});
function toggleDarkMode() {
    var html = document.documentElement;
    var body = document.body;
    body.classList.toggle('dark-mode');
    if (body.classList.contains('dark-mode')) {
        document.cookie = 'dark_mode=1; path=/; max-age=' + (86400 * 365);
    } else {
        document.cookie = 'dark_mode=; path=/; max-age=0';
    }
}
// ── Session inactivity lock screen (client-side UX) ─────────────────────
// Server-side enforcement lives in auth.php (enforceSessionTimeout). This adds a
// lightweight inactivity detector that surfaces a Lock Screen modal and submits the
// password to unlock, so the user can resume their session without a full logout.
(function() {
    if (window.__naredIdleStarted) return; // never create duplicate timers/modals
    window.__naredIdleStarted = true;

    if (!document.body) return;

    var timeoutMinutes = (typeof SESSION_TIMEOUT_MINUTES === 'number' && SESSION_TIMEOUT_MINUTES > 0)
        ? SESSION_TIMEOUT_MINUTES
        : 30;
    var idleTimer = null;
    var lockShown = false;
    var csrf = (typeof csrfToken !== 'undefined') ? csrfToken : '';

    function buildLockOverlay() {
        var d = document.createElement('div');
        d.id = 'nared-lock-overlay';
        d.setAttribute('role', 'dialog');
        d.setAttribute('aria-modal', 'true');
        d.innerHTML =
            '<div style="position:fixed;inset:0;z-index:2147483647;background:rgba(15,23,42,0.72);display:flex;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(3px)">' +
                '<div style="background:#fff;border-radius:20px;max-width:380px;width:100%;box-shadow:0 20px 48px rgba(0,0,0,.35);overflow:hidden">' +
                    '<div style="background:linear-gradient(160deg,#1e3a5f,#1e40af 50%,#1a56db);padding:22px 24px;text-align:center;color:#fff">' +
                        '<div style="width:52px;height:52px;margin:0 auto;border-radius:50%;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:1.4rem"><i class="fas fa-lock"></i></div>' +
                        '<div style="margin-top:10px;font-weight:800;font-size:1.05rem">Session Locked</div>' +
                        '<div style="font-size:.78rem;opacity:.85;margin-top:2px">Inactive for ' + timeoutMinutes + ' minutes</div>' +
                    '</div>' +
                    '<div style="padding:22px 24px 20px">' +
                        '<div id="nared-lock-msg" style="display:none;background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;padding:9px 12px;border-radius:8px;font-size:.82rem;margin-bottom:12px"></div>' +
                        '<label style="display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:6px">Enter your password to continue</label>' +
                        '<div style="display:flex;gap:10px;align-items:center;border:2px solid #d1d5db;border-radius:8px;padding:9px 12px">' +
                            '<input id="nared-lock-password" type="password" autocomplete="current-password" placeholder="Your password" style="flex:1;border:none;outline:none;font:inherit;font-size:.9rem" />' +
                        '</div>' +
                        '<div style="display:flex;gap:10px;margin-top:16px">' +
                            '<button id="nared-lock-unlock" type="button" style="flex:1;background:linear-gradient(180deg,#00a35e,#006838);color:#fff;font-weight:800;padding:11px;border:none;border-radius:8px;cursor:pointer;font-size:.9rem">Unlock</button>' +
                            '<button id="nared-lock-logout" type="button" style="background:#f3f4f6;color:#374151;font-weight:700;padding:11px 14px;border:none;border-radius:8px;cursor:pointer;font-size:.85rem">Log out</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(d);
        d.querySelector('#nared-lock-password').focus();
        d.querySelector('#nared-lock-password').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') doUnlock();
        });
        d.querySelector('#nared-lock-unlock').addEventListener('click', doUnlock);
        d.querySelector('#nared-lock-logout').addEventListener('click', function() {
            window.location.href = 'index.php?page=logout';
        });
    }

    function doUnlock() {
        var pw = document.getElementById('nared-lock-password');
        var btn = document.getElementById('nared-lock-unlock');
        var msg = document.getElementById('nared-lock-msg');
        if (!pw.value) return;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';
        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('password', pw.value);
        fetch('index.php?page=lock&ajax=unlock', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    window.location.href = res.redirect ? 'index.php?page=' + res.redirect : 'index.php';
                } else {
                    msg.style.display = 'block';
                    msg.textContent = res.message || 'Incorrect password.';
                    pw.value = '';
                    btn.disabled = false;
                    btn.textContent = 'Unlock';
                    pw.focus();
                }
            })
            .catch(function() {
                msg.style.display = 'block';
                msg.textContent = 'Unlock failed. Please try again.';
                btn.disabled = false;
                btn.textContent = 'Unlock';
            });
    }

    function showLock() {
        if (lockShown) return;
        lockShown = true;
        buildLockOverlay();
    }

    function onIdle() {
        showLock();
    }

    function resetIdle() {
        if (lockShown) return; // keep the lock screen up once shown
        if (idleTimer) clearTimeout(idleTimer);
        idleTimer = setTimeout(onIdle, timeoutMinutes * 60 * 1000);
    }

    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach(function(evt) {
        document.addEventListener(evt, resetIdle, { passive: true });
    });

    resetIdle();
})();
</script>
<?php while (ob_get_level()) { ob_end_flush(); } ?>
</body>
</html>
