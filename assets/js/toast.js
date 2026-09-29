function showToast(message, type) {
    type = type || 'success';
    var container = document.getElementById('toast-container');
    if (!container) return;
    var icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle', warning: 'fa-exclamation-triangle' };
    var colors = { success: '#00a35e', error: '#dc2626', info: '#2563eb', warning: '#d97706' };
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.innerHTML = '<i class="fas ' + (icons[type] || icons.info) + '"></i> <span>' + message + '</span>';
    toast.style.borderLeftColor = colors[type] || colors.info;
    container.appendChild(toast);
    setTimeout(function () {
        toast.classList.add('toast-hide');
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 400);
    }, 1500);
}

(function () {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function (el) {
        var msg = el.textContent.trim();
        if (!msg) return;
        var type = 'info';
        if (el.classList.contains('alert-success')) type = 'success';
        else if (el.classList.contains('alert-error')) type = 'error';
        else if (el.classList.contains('alert-warning')) type = 'warning';
        showToast(msg, type);
        el.style.display = 'none';
    });

    // Support Bootstrap-style alert dismiss buttons (data-bs-dismiss="alert")
    // even though Bootstrap itself is not loaded on the portal.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-bs-dismiss="alert"]');
        if (!btn) return;
        var alertEl = btn.closest('.alert');
        if (!alertEl) return;
        alertEl.style.transition = 'opacity .2s';
        alertEl.style.opacity = '0';
        setTimeout(function () {
            if (alertEl.parentNode) alertEl.parentNode.removeChild(alertEl);
        }, 210);
    }, false);
})();
