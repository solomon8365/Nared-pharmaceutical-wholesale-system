/**
 * Universal AJAX form interceptor.
 *
 * Intercepts every <form method="post"> in the application, sends it via
 * fetch, shows a SweetAlert2 toast on completion, and performs generic
 * in-place DOM updates (badge changes, row removal, counter tweaks).
 *
 * No per-page PHP changes required — index.php handles the JSON conversion.
 */
(function () {
  'use strict';

  /* ── Helpers ─────────────────────────────────────────────────────────── */

  /** Show a non-blocking toast (single shared toast system). */
  function toast(icon, text) {
    if (typeof window.showToast === 'function') {
      window.showToast(text, icon === 'error' ? 'error' : icon === 'warning' ? 'warning' : icon === 'info' ? 'info' : 'success');
      return;
    }
    if (typeof Swal === 'undefined') return;           // graceful fallback
    Swal.fire({
      toast: true,
      position: 'top-end',
      icon: icon,
      title: text,
      showConfirmButton: false,
      timer: 2200,
      timerProgressBar: true,
      didOpen: function (el) { el.style.zIndex = 99999; }
    });
  }

  /** Add a small spinner to a button while the request is in flight. */
  function busy(btn) {
    if (!btn) return;
    btn.dataset.ajaxOrigHtml = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('ajax-busy');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  }

  /** Restore a button after the request completes. */
  function idle(btn) {
    if (!btn) return;
    btn.disabled = false;
    btn.classList.remove('ajax-busy');
    if (btn.dataset.ajaxOrigHtml) {
      btn.innerHTML = btn.dataset.ajaxOrigHtml;
      delete btn.dataset.ajaxOrigHtml;
    }
  }

  /** Walk up from el and return the closest <tr>, if any. */
  function closestRow(el) {
    return el ? el.closest('tr') : null;
  }

  /** Find the first badge / status pill inside a given element. */
  function findBadge(el) {
    return el ? el.querySelector('.fin-badge, .status-badge, .sd-badge, .sd-exp-bucket, span[style*="border-radius"]') : null;
  }

  /* ── Generic DOM update heuristics ───────────────────────────────────── */

  /**
   * After a successful action, try to update the page in-place.
   * Falls back gracefully when it can't figure out what to do.
   */
  function domUpdate(form, data) {
    var action = (form.querySelector('[name="_action"]') || {}).value || '';
    var row    = closestRow(form);
    var isDelete = /delete|cancel|remove|reject|deactivate/i.test(action);
    var isApprove = /approve|confirm|complete|activate|mark_paid|set_paid/i.test(action);

    /* 1) Deleted / cancelled rows → fade out and remove */
    if (isDelete && row) {
      row.style.transition = 'opacity .3s, max-height .3s';
      row.style.opacity = '0';
      row.style.maxHeight = '0';
      row.style.overflow = 'hidden';
      setTimeout(function () { row.remove(); }, 320);
      return;
    }

    /* 2) Approved / confirmed actions → flip the badge to green */
    if (isApprove && row) {
      var badge = findBadge(row);
      if (badge) {
        badge.textContent = badge.textContent.replace(/.*/, '✓ Done');
        badge.style.background = '#dcfce7';
        badge.style.color = '#166534';
        badge.style.borderColor = '#bbf7d0';
      }
    }

    /* 3) Generic counter badges on the page (e.g. "sd-wf-count") → decrement */
    if (isDelete) {
      document.querySelectorAll('.sd-wf-count').forEach(function (c) {
        var n = parseInt(c.textContent, 10);
        if (!isNaN(n) && n > 0) c.textContent = n - 1;
      });
    }

    /* 4) Data-ajax-update: server can request updating specific elements.
          Not used yet but ready for future per-page DOM hints. */
    if (data && data.update) {
      Object.keys(data.update).forEach(function (sel) {
        var el = document.querySelector(sel);
        if (el) el.textContent = data.update[sel];
      });
    }
  }

  /* ── Core: intercept all POST forms ──────────────────────────────────── */

  /** Show the PRG flash toast recovered from a followed redirect's HTML. */
  function flashToast(html) {
    var m = /showToast\(\s*"((?:[^"\\]|\\.)*)"\s*,\s*"((?:[^"\\]|\\.)*)"\s*\)/.exec(html || '');
    if (m) toast(m[2], m[1].replace(/\\(["\\\/nrt])/g, '$1'));
    else toast('success', 'Action completed');
  }

  /** Navigate exactly where the server sent us (PRG target) after feedback. */
  function navigateAfter(url) {
    setTimeout(function () {
      if (url && url !== location.href) location.href = url;
      else location.reload();
    }, 800);
  }

  document.addEventListener('submit', function (e) {
    // Only intercept the *real* submit. If another capture-phase handler
    // (e.g. swal.js confirmation) already called preventDefault, that handler
    // will re-dispatch this same form via requestSubmit() after its async
    // dialog resolves. Acting here would double-submit.
    if (e.defaultPrevented) return;

    var form = e.target;
    if (form.tagName !== 'FORM') return;
    if ((form.method || '').toLowerCase() !== 'post') return;
    // Skip forms that opt-out (e.g. file uploads that need special handling)
    if (form.hasAttribute('data-ajax-off')) return;

    e.preventDefault();

    // Use the actually-clicked submitter so the correct button shows the
    // spinner when a form has multiple submit buttons.
    var btn = e.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
    busy(btn);

    var fd = new FormData(form);
    // Flag so index.php starts output buffering → JSON path
    fd.set('_ajax', '1');
    // Capture whether swal.js already confirmed this submission (snapshot so
    // the flag can't leak into a later submit of the same form element).
    var swalConfirmed = form.dataset.swalConfirmed === 'true';
    delete form.dataset.swalConfirmed;

    // Resolve the POST target exactly like native form submission.  We must NOT
    // read the `form.action` property: many forms carry a hidden control named
    // "action" (e.g. <input name="action" value="approve">), and the HTML named
    // getter shadows the built-in action property, so form.action returns the
    // element — which coerces to "[object HTMLInputElement]" and breaks the URL
    // (server error 404).  Reading the attributes (formaction / action) avoids
    // that shadowing.  Falls back to the current document URL, like a form
    // without an action attribute.
    var actionUrl =
      (btn && btn.getAttribute('formaction')) ||
      form.getAttribute('action') ||
      location.href;

    var finalUrl = null;

    fetch(actionUrl, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
    .then(function (r) {
      finalUrl = r.url;
      if (!r.ok) throw new Error('Server error ' + r.status);
      return r.text();
    })
    .then(function (text) {
      var data;
      var workflowType = window.detectActionType ? window.detectActionType(form, btn) : null;
      try {
        data = JSON.parse(text);
      } catch (e) {
        idle(btn);
        if (workflowType) {
          domUpdate(form, {});
          if (swalConfirmed || typeof window.actionNotify !== 'function') {
            flashToast(text);
          } else {
            window.actionNotify(workflowType).then(function () {
              navigateAfter(finalUrl);
            });
            return;
          }
        } else {
          flashToast(text);
        }
        navigateAfter(finalUrl);
        return;
      }
      idle(btn);

      /* Determine whether the action succeeded (no error alerts) */
      var hasError = !!(data.messages && data.messages.some(function (m) { return m.type === 'error'; }));

      /* Standardized workflow action → OK result modal (replaces toast) */
      if (workflowType && !hasError) {
        /* Apply smart in-place DOM update first (badge flip / row removal) */
        domUpdate(form, data);
        if (swalConfirmed || typeof window.actionNotify !== 'function') {
          if (data.messages && data.messages.length) {
            data.messages.forEach(function (m) {
              toast(m.type === 'error' ? 'error' : 'success', m.text);
            });
          } else {
            toast('success', 'Action completed');
          }
          navigateAfter(finalUrl);
          return;
        }
        window.actionNotify(workflowType).then(function () {
          navigateAfter(finalUrl);
        });
        return;
      }

      /* Show toast(s) */
      if (data.messages && data.messages.length) {
        data.messages.forEach(function (m) {
          toast(m.type === 'error' ? 'error' : 'success', m.text);
        });
      } else if (data.success && !workflowType) {
        toast('success', 'Action completed');
      }

      /* Try a smart in-place DOM update */
      domUpdate(form, data);
    })
    .catch(function (err) {
      idle(btn);
      toast('error', err.message || 'Something went wrong');
    });
  }, false);

  /* ── Intercept standalone action buttons outside forms ────────────────── */

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-confirm-action], a[data-confirm-action]');
    if (!btn) return;

    var actionUrl = btn.dataset.confirmAction || btn.href;
    if (!actionUrl) return;

    e.preventDefault();
    var label = btn.dataset.confirmLabel || 'Yes, proceed';

    if (typeof Swal !== 'undefined') {
      Swal.fire({
        title: 'Are you sure?',
        text: btn.dataset.confirmMsg || '',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: label,
        confirmButtonColor: '#16a34a'
      }).then(function (result) {
        if (result.isConfirmed) fireStandalone(btn, actionUrl);
      });
    } else {
      if (!confirm(btn.dataset.confirmMsg || 'Are you sure?')) return;
      fireStandalone(btn, actionUrl);
    }
  }, false);

  function fireStandalone(btn, url) {
    busy(btn);
    fetch(url, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      idle(btn);
      if (data.messages) data.messages.forEach(function (m) {
        toast(m.type === 'error' ? 'error' : 'success', m.text);
      });
      // Standalone actions usually need a page update
      if (data.reload || (data.success && !data.update)) {
        setTimeout(function () { location.reload(); }, 400);
      }
      if (data.update) {
        Object.keys(data.update).forEach(function (sel) {
          var el = document.querySelector(sel);
          if (el) el.textContent = data.update[sel];
        });
      }
    })
    .catch(function (err) {
      idle(btn);
      toast('error', err.message || 'Request failed');
    });
  }

})();
