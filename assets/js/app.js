$(document).ready(function () {
    let rowIndex = 1;

    function buildOrderRow(index) {
        const productOptions = $('select[name="items[0][product_id]"]').html();
        const row = $('<div class="order-item-row"></div>');
        row.append('<select name="items[' + index + '][product_id]" required>' + productOptions + '</select>');
        row.append('<input type="number" name="items[' + index + '][quantity]" value="1" min="1" required>');
        row.append('<input type="text" name="items[' + index + '][batch_number]" placeholder="Batch #" style="min-width:90px">');
        row.append('<input type="date" name="items[' + index + '][expiry_date]" style="min-width:130px">');
        row.append('<input type="number" step="0.01" name="items[' + index + '][purchase_price]" placeholder="Cost" style="min-width:90px">');
        row.append('<button type="button" class="remove-item">Remove</button>');
        return row;
    }

    $('#add-order-item').on('click', function () {
        $('#order-items').append(buildOrderRow(rowIndex));
        rowIndex++;
    });

    $('body').on('click', '.remove-item', function () {
        $(this).closest('.order-item-row').remove();
    });

    let saleRowIndex = 1;

    function buildSaleRow(index) {
        const productOptions = $('select[name="sale_items[0][product_id]"]').html();
        const row = $('<div class="order-item-row"></div>');
        row.append('<select name="sale_items[' + index + '][product_id]" required>' + productOptions + '</select>');
        row.append('<input type="number" name="sale_items[' + index + '][quantity]" value="1" min="1" required>');
        row.append('<button type="button" class="remove-item">Remove</button>');
        return row;
    }

    $('#add-sale-item').on('click', function () {
        $('#sale-items').append(buildSaleRow(saleRowIndex));
        saleRowIndex++;
    });

    let requestRowIndex = 1;

    function buildRequestRow(index) {
        const productOptions = $('select[name="request_items[0][product_id]"]').html();
        const row = $('<div class="order-item-row"></div>');
        row.append('<select name="request_items[' + index + '][product_id]" required>' + productOptions + '</select>');
        row.append('<input type="number" name="request_items[' + index + '][quantity]" value="1" min="1" required>');
        row.append('<button type="button" class="remove-item">Remove</button>');
        return row;
    }

    $('#add-request-item').on('click', function () {
        $('#request-items').append(buildRequestRow(requestRowIndex));
        requestRowIndex++;
    });
});

function setSidebarGroupHeight(group, open, animate) {
    var content = group.querySelector('.sidebar-group-content');
    if (!content) return;
    if (open) {
        content.style.display = 'block';
        if (!animate) {
            content.style.height = 'auto';
        } else {
            content.style.height = '0px';
            content.getBoundingClientRect();
            content.style.height = content.scrollHeight + 'px';
            var done = function () {
                if (group.classList.contains('open')) content.style.height = 'auto';
                content.removeEventListener('transitionend', done);
            };
            content.addEventListener('transitionend', done);
        }
    } else {
        if (content.style.height === 'auto' || content.style.height === '') {
            content.style.height = content.scrollHeight + 'px';
            content.getBoundingClientRect();
        }
        content.style.height = '0px';
    }
}

function initSidebarAccordion() {
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    var nav = sidebar.querySelector('.sidebar-nav');
    if (!nav) return;
    var activeGroup = null;
    nav.querySelectorAll('.sidebar-group').forEach(function (g) {
        if (g.classList.contains('open')) activeGroup = g;
    });
    nav.querySelectorAll('.sidebar-group').forEach(function (g) {
        if (g === activeGroup) {
            setSidebarGroupHeight(g, true, false);
        } else {
            g.classList.remove('open');
            setSidebarGroupHeight(g, false, false);
        }
    });
    if (!activeGroup) {
        var last = null;
        try { last = localStorage.getItem('sidebar_open_group'); } catch (e) {}
        if (last) {
            var saved = document.getElementById('group-' + last);
            if (saved) {
                var sg = saved.closest('.sidebar-group');
                if (sg) {
                    sg.classList.add('open');
                    setSidebarGroupHeight(sg, true, false);
                }
            }
        }
    }
}

function toggleGroup(id) {
    var el = document.getElementById('group-' + id);
    if (!el) return;
    var parent = el.closest('.sidebar-group');
    if (!parent) return;
    var sidebar = document.getElementById('sidebar');
    if (sidebar && sidebar.classList.contains('collapsed')) {
        showSidebarFlyout(parent.querySelector('.sidebar-group-header'));
        return;
    }
    var wasOpen = parent.classList.contains('open');
    if (wasOpen) {
        parent.classList.remove('open');
        setSidebarGroupHeight(parent, false, true);
        try { localStorage.removeItem('sidebar_open_group'); } catch (e) {}
    } else {
        var nav = parent.parentElement;
        if (nav) {
            nav.querySelectorAll('.sidebar-group.open').forEach(function (g) {
                g.classList.remove('open');
                setSidebarGroupHeight(g, false, true);
            });
        }
        parent.classList.add('open');
        setSidebarGroupHeight(parent, true, true);
        try { localStorage.setItem('sidebar_open_group', id); } catch (e) {}
    }
}

var _flyoutTimer = null;
function getSidebarFlyout() {
    var f = document.getElementById('sidebar-flyout');
    if (!f) {
        f = document.createElement('div');
        f.id = 'sidebar-flyout';
        f.className = 'sidebar-flyout';
        f.addEventListener('mouseenter', function () { clearTimeout(_flyoutTimer); });
        document.body.appendChild(f);
    }
    return f;
}
function showSidebarFlyout(header) {
    if (!header) return;
    var sidebar = document.getElementById('sidebar');
    if (!sidebar || !sidebar.classList.contains('collapsed')) return;
    var group = header.closest('.sidebar-group');
    var content = group ? group.querySelector('.sidebar-group-content') : null;
    if (!content || !content.children.length) return;
    clearTimeout(_flyoutTimer);
    var f = getSidebarFlyout();
    f.innerHTML = content.innerHTML;
    var rect = header.getBoundingClientRect();
    var top = rect.top;
    var maxTop = window.innerHeight - 60;
    if (top > maxTop) top = Math.max(maxTop, 0);
    f.style.top = top + 'px';
    f.style.left = (rect.right + 1) + 'px';
    f.style.opacity = '1';
    f.style.visibility = 'visible';
}
function hideSidebarFlyout() {
    clearTimeout(_flyoutTimer);
    var f = document.getElementById('sidebar-flyout');
    if (f) { f.style.opacity = '0'; f.style.visibility = 'hidden'; }
}
function scheduleHideSidebarFlyout() {
    clearTimeout(_flyoutTimer);
    _flyoutTimer = setTimeout(hideSidebarFlyout, 180);
}

function applySidebarTooltips() {
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    var collapsed = sidebar.classList.contains('collapsed');
    var items = sidebar.querySelectorAll('.sidebar-link, .sidebar-group-header');
    items.forEach(function (it) {
        var label = '';
        var spans = it.querySelectorAll('span');
        for (var i = 0; i < spans.length; i++) {
            if (!spans[i].getAttribute('style')) { label = spans[i].textContent; break; }
        }
        label = label.replace(/\s+/g, ' ').trim();
        if (collapsed && label) {
            it.setAttribute('title', label);
        } else {
            it.removeAttribute('title');
        }
    });
}

function syncSidebarCollapsed() {
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    var w = window.innerWidth;
    if (w <= 767) {
        sidebar.classList.remove('collapsed');
    } else if (w <= 1100) {
        sidebar.classList.add('collapsed');
    } else {
        var saved = '0';
        try { saved = localStorage.getItem('sidebar_collapsed') || '0'; } catch (e) {}
        if (saved === '1') sidebar.classList.add('collapsed');
        else sidebar.classList.remove('collapsed');
    }
    applySidebarTooltips();
}

function collapseSidebar() {
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    if (window.innerWidth <= 767) {
        toggleSidebar();
        return;
    }
    var collapsed = sidebar.classList.toggle('collapsed');
    try { localStorage.setItem('sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) {}
    if (!collapsed) hideSidebarFlyout();
    applySidebarTooltips();
    initSidebarAccordion();
}

document.addEventListener('DOMContentLoaded', function() {
    initSidebarAccordion();
    syncSidebarCollapsed();
    injectFilterExportButtons();
    var sidebarEl = document.getElementById('sidebar');
    if (sidebarEl) {
        var navEl = sidebarEl.querySelector('.sidebar-nav');
        if (navEl) navEl.addEventListener('scroll', hideSidebarFlyout);
    }
    window.addEventListener('resize', function () {
        syncSidebarCollapsed();
        hideSidebarFlyout();
    });

    document.addEventListener('mouseover', function (e) {
        var sidebar = document.getElementById('sidebar');
        if (sidebar && !sidebar.classList.contains('collapsed')) { hideSidebarFlyout(); return; }
        var target = e.target;
        if (!target || typeof target.closest !== 'function') return;
        var h = target.closest('.sidebar-group-header');
        if (h) { showSidebarFlyout(h); return; }
        if (target.closest('#sidebar-flyout')) return;
        scheduleHideSidebarFlyout();
    });
    document.addEventListener('click', function (e) {
        var target = e.target;
        if (!target || typeof target.closest !== 'function') return;
        if (!target.closest('#sidebar-flyout') && !target.closest('.sidebar-group-header')) {
            hideSidebarFlyout();
        }
    });

    // Export dropdown: close on outside click
    document.addEventListener('click', function(e) {
        document.querySelectorAll('.export-dropdown-menu.show').forEach(function(m) {
            if (!m.parentElement.contains(e.target)) {
                m.classList.remove('show');
            }
        });
    });
});

function downloadExportFile(name, content, type) {
    var blob = new Blob([content], { type: type });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1500);
}

function exportFilteredTables(el, type) {
    var scope = el.closest('.panel') || el.closest('form');
    var tables = scope ? scope.querySelectorAll('table') : [];
    if (!tables.length) {
        var main = document.querySelector('.main-content') || document.querySelector('main') || document;
        tables = main.querySelectorAll('table');
    }
    var visible = [];
    tables.forEach(function (t) {
        var rects = t.getClientRects ? t.getClientRects() : [];
        if (rects.length) visible.push(t);
    });
    if (!visible.length) return;
    var dateStr = new Date().toISOString().slice(0, 10);
    if (type === 'xls') {
        var html = '';
        visible.forEach(function (t) {
            var clone = t.cloneNode(true);
            clone.querySelectorAll('img, svg, button, form, select, input, textarea, iframe, canvas').forEach(function (n) {
                var rep = document.createTextNode(n.textContent || '');
                n.parentNode.replaceChild(rep, n);
            });
            html += clone.outerHTML;
        });
        var content = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"></head><body>' + html + '</body></html>';
        downloadExportFile('export_' + dateStr + '.xls', content, 'application/vnd.ms-excel;charset=utf-8;');
    } else {
        var parts = [];
        visible.forEach(function (t) {
            var lines = [];
            t.querySelectorAll('tr').forEach(function (tr) {
                var cells = [];
                tr.querySelectorAll('th, td').forEach(function (c) {
                    var s = (c.textContent || '').replace(/\s+/g, ' ').trim();
                    if (/[",\r\n]/.test(s)) s = '"' + s.replace(/"/g, '""') + '"';
                    cells.push(s);
                });
                if (cells.length) lines.push(cells.join(','));
            });
            if (lines.length) parts.push(lines.join('\r\n'));
        });
        downloadExportFile('export_' + dateStr + '.csv', '\ufeff' + parts.join('\r\n\r\n'), 'text/csv;charset=utf-8;');
    }
}

function injectFilterExportButtons() {
    document.querySelectorAll('form').forEach(function (form) {
        if (form.querySelector('.export-dropdown, .export-btn')) return;
        if (form.closest('table')) return;
        var hasFilter = false;
        form.querySelectorAll('button, input[type="submit"]').forEach(function (b) {
            var label = b.value || b.textContent || '';
            if (/filter/i.test(label)) hasFilter = true;
        });
        if (!hasFilter) {
            var method = String(form.method || 'get').toLowerCase();
            if (method !== 'get') return;
            if (!form.querySelector('input[type="hidden"][name="page"]')) return;
            if (!form.querySelector('button[type="submit"], input[type="submit"]')) return;
            var isFilterForm = false;
            form.querySelectorAll('select, input[type="date"], input[type="datetime-local"], input[type="month"], input[type="text"]').forEach(function (f) {
                if (f.type === 'date' || f.type === 'datetime-local' || f.type === 'month') { isFilterForm = true; return; }
                if (f.tagName === 'SELECT') { isFilterForm = true; return; }
                var n = (f.getAttribute('name') || '').toLowerCase();
                if (n === 'search' || n === 'q' || n === 'ip') isFilterForm = true;
            });
            if (!isFilterForm) return;
        }
        if (form.id === 'filterForm') return;
        var panel = form.closest('.panel, .tab-panel, .fin-card, .as-card, .del-card, .cr-panel');
        var dup = false;
        document.querySelectorAll('.export-dropdown, .export-btn-wrap, [onclick*="export"], [onclick*="exportTable"]').forEach(function (x) {
            if (form.contains(x)) dup = true;
            if (panel && panel.contains(x)) dup = true;
        });
        if (dup) return;
        var scope = form.closest('.panel') || form;
        var tables = scope ? scope.querySelectorAll('table') : [];
        if (!tables.length) {
            var main = document.querySelector('.main-content') || document.querySelector('main') || document;
            tables = main.querySelectorAll('table');
        }
        if (!tables.length) return;
        var dd = document.createElement('div');
        dd.className = 'export-dropdown';
        dd.innerHTML = '<button type="button" class="export-btn" onclick="toggleExport(this)"><i class="fas fa-download"></i> Export</button>' +
            '<div class="export-dropdown-menu">' +
            '<a href="#" onclick="exportFilteredTables(this,\'csv\');return false;"><i class="fas fa-file-csv"></i> CSV</a>' +
            '<a href="#" onclick="exportFilteredTables(this,\'xls\');return false;"><i class="fas fa-file-excel"></i> Excel</a>' +
            '</div>';
        form.appendChild(dd);
    });
}

function toggleExport(el) {
    var menu = el.parentElement.querySelector('.export-dropdown-menu');
    if (menu) {
        var isOpen = menu.classList.contains('show');
        // Close all other dropdowns first
        document.querySelectorAll('.export-dropdown-menu.show').forEach(function(m) {
            m.classList.remove('show');
        });
        // Toggle this one
        if (!isOpen) {
            menu.classList.add('show');
        }
    }
}

function switchTab(el, tabId) {
    var bar = el.parentElement;
    var btns = bar.querySelectorAll('.tab-btn');
    btns.forEach(function(b) { b.classList.remove('active'); });
    el.classList.add('active');
    var content = bar.nextElementSibling;
    while (content && !content.classList.contains('tab-content')) { content = content.nextElementSibling; }
    if (content) {
        var panels = content.querySelectorAll('.tab-panel');
        panels.forEach(function(p) { p.classList.remove('active'); });
        var target = document.getElementById(tabId);
        if (target) target.classList.add('active');
    }
}
