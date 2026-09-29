(function () {
    var buffer = '';
    var lastTime = 0;
    var minInterval = 50;
    var scannerActive = false;
    var scannerTimeout = null;
    var cameraScanner = null;

    function handleScan(code) {
        var evt = new CustomEvent('barcode-scanned', { detail: { code: code } });
        document.dispatchEvent(evt);
        var focused = document.activeElement;
        if (focused) {
            var fevt = new CustomEvent('barcode-scanned', { bubbles: true, detail: { code: code } });
            focused.dispatchEvent(fevt);
        }
    }

    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.altKey || e.metaKey) return;
        var now = Date.now();
        var interval = now - lastTime;
        lastTime = now;

        if (interval > 0 && interval < minInterval && e.key.length === 1) {
            scannerActive = true;
            document.body.classList.add('scanner-active');
            if (scannerTimeout) clearTimeout(scannerTimeout);
            scannerTimeout = setTimeout(function () {
                scannerActive = false;
                document.body.classList.remove('scanner-active');
            }, 200);
        }

        if (scannerActive) {
            e.preventDefault();
            e.stopPropagation();

            if (e.key === 'Enter') {
                if (buffer.length >= 4) {
                    handleScan(buffer);
                }
                buffer = '';
                scannerActive = false;
                document.body.classList.remove('scanner-active');
                if (scannerTimeout) clearTimeout(scannerTimeout);
                return;
            }

            if (e.key.length === 1) {
                buffer += e.key;
            } else if (e.key === 'Tab') {
                if (buffer.length >= 4) {
                    handleScan(buffer);
                }
                buffer = '';
                scannerActive = false;
                document.body.classList.remove('scanner-active');
                if (scannerTimeout) clearTimeout(scannerTimeout);
            }
        } else {
            if (e.key.length === 1) {
                buffer = e.key;
                lastTime = now;
            } else {
                buffer = '';
            }
        }
    }, true);

    // ===== Camera Barcode Scanner =====
    var camStyles = document.createElement('style');
    camStyles.textContent =
        '.camera-scanner-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;display:flex;align-items:center;justify-content:center}' +
        '.camera-scanner-box{background:#fff;border-radius:12px;padding:20px;max-width:500px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.3);position:relative}' +
        '.camera-scanner-box h3{margin:0 0 12px;font-size:1.1rem}' +
        '.camera-scanner-box .cs-close{position:absolute;top:10px;right:14px;background:none;border:none;font-size:1.4rem;cursor:pointer;color:#666}' +
        '.camera-scanner-box #camera-reader{width:100%;min-height:200px;border-radius:8px;overflow:hidden}' +
        '.camera-scanner-box .cs-hint{text-align:center;font-size:0.85rem;color:#888;margin-top:8px}';
    document.head.appendChild(camStyles);

    window.closeCameraScanner = function () {
        if (cameraScanner) {
            try { cameraScanner.stop(); } catch(e) {}
            try { cameraScanner.clear(); } catch(e) {}
            cameraScanner = null;
        }
        var ov = document.querySelector('.camera-scanner-overlay');
        if (ov) ov.remove();
    }

    window.openCameraScanner = function () {
        if (typeof Html5Qrcode === 'undefined') { alert('Camera scanner library not loaded. Please refresh the page.'); return; }
        window.closeCameraScanner();
        var overlay = document.createElement('div');
        overlay.className = 'camera-scanner-overlay';
        overlay.innerHTML =
            '<div class="camera-scanner-box">' +
            '<button class="cs-close" onclick="closeCameraScanner()">&times;</button>' +
            '<h3>Scan Barcode</h3>' +
            '<div id="camera-reader"></div>' +
            '<p class="cs-hint">Point your camera at a barcode</p>' +
            '</div>';
        document.body.appendChild(overlay);
        overlay.addEventListener('click', function (ev) { if (ev.target === overlay) window.closeCameraScanner(); });
        document.addEventListener('keydown', function escHandler(ev) {
            if (ev.key === 'Escape') { window.closeCameraScanner(); document.removeEventListener('keydown', escHandler); }
        });

        cameraScanner = new Html5Qrcode('camera-reader');
        cameraScanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 250, height: 150 }, formatsToSupport: [
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.EAN_8,
                Html5QrcodeSupportedFormats.UPC_A,
                Html5QrcodeSupportedFormats.UPC_E,
                Html5QrcodeSupportedFormats.CODE_39,
                Html5QrcodeSupportedFormats.CODE_93,
                Html5QrcodeSupportedFormats.ITF,
                Html5QrcodeSupportedFormats.CODABAR
            ]},
            function (decodedText) {
                handleScan(decodedText);
                window.closeCameraScanner();
            },
            function () {}
        ).catch(function (err) {
            var hint = document.querySelector('.cs-hint');
            if (String(err).indexOf('NotAllowedError') !== -1 || String(err).indexOf('Permission denied') !== -1) {
                hint.innerHTML = '<strong style="color:#dc2626">Camera access denied.</strong><br>To fix: click the camera icon in the address bar (or lock icon) and select <em>Allow</em>, then try again.<br>Or check <strong>Settings &gt; Privacy &gt; Camera</strong> in your browser.';
            } else if (String(err).indexOf('NotFoundError') !== -1) {
                hint.innerHTML = '<strong style="color:#dc2626">No camera found.</strong><br>Make sure a camera is connected and not in use by another app.';
            } else {
                hint.textContent = 'Camera error: ' + err;
            }
        });
    };
})();
