/**
 * SweetAlert2 Integration — NARED PHARMACON
 * Auto-replaces native confirm() dialogs with styled SweetAlert2 popups.
 *
 * Features:
 *   • Extracts the real confirm message text and displays it as the dialog body
 *   • Per-type icons: FontAwesome solid icons matching the action context
 *   • Animated entrance: icon bounces in, content fades up
 *   • Three dialog styles:
 *       delete  — red accent, trash icon, "Yes, delete it!" button
 *       approve — green accent, check icon, "Yes, approve!" button
 *       success — blue accent, save icon, "OK" button
 *
 * Detection: CSS classes, hidden _action inputs, button text.
 * Override:   data-swal-type="delete|approve|success"
 * Skip:       data-swal-exclude="true"
 */
(function () {
  'use strict';

  /* ── Inject badge / animation CSS once ──────────────────────────────── */
  var style = document.createElement('style');
  style.textContent = [
    '.swal2-html-container .swal-custom{overflow:visible}',
    '.swal2-popup.swal2-popup.swal-custom-popup{overflow:visible}',
    '.swal2-icon.swal2-icon-fa{border:none !important;background:none !important;width:64px !important;height:64px !important;margin:8px auto 12px !important;opacity:0;transform:scale(0) rotate(-15deg);transition:opacity .35s ease,transform .45s cubic-bezier(.17,.89,.32,1.28);animation:none !important}',
    '.swal2-icon.swal2-icon-fa.swal2-icon-fa-show{opacity:1;transform:scale(1) rotate(0)}',
    '.swal2-icon.swal2-icon-fa svg{display:none}',
    '.swal2-icon.swal2-icon-fa i{font-size:2.4rem;line-height:64px;color:#fff}',
    '.swal-badge{display:inline-block;padding:5px 16px;border-radius:999px;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;line-height:1.2;opacity:0;transform:translateY(8px);transition:opacity .3s .15s ease,transform .3s .15s ease}',
    '.swal-badge.show{opacity:1;transform:translateY(0)}',
    '.swal-badge-red{background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;box-shadow:0 2px 10px rgba(220,38,38,0.25)}',
    '.swal-badge-green{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;box-shadow:0 2px 10px rgba(22,163,74,0.25)}',
    '.swal-badge-blue{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 2px 10px rgba(37,99,235,0.25)}',
    '.swal-badge-amber{background:linear-gradient(135deg,#d97706,#b45309);color:#fff;box-shadow:0 2px 10px rgba(217,119,6,0.25)}',
    '.swal-msg{margin:8px 0 0;color:#475569;font-size:.92rem;line-height:1.55;max-width:320px;margin-left:auto;margin-right:auto}',
    '.swal2-html-container .swal-title-custom{font-size:.92rem;color:#1e293b;line-height:1.45;margin-top:2px}',
    '.swal2-actions .swal2-styled{border-radius:10px !important;font-weight:700 !important;padding:10px 22px !important;font-size:.85rem !important;transition:all .15s ease !important}',
    '.swal2-actions .swal2-styled:hover{transform:translateY(-1px) !important;box-shadow:0 4px 12px rgba(0,0,0,.15) !important}',
    '.swal2-actions .swal2-styled:active{transform:translateY(0) !important}',
    '.swal2-actions .swal2-deny{display:none}',
    'body.dark-mode .swal-msg{color:#94a3b8}',
    'body.dark-mode .swal2-html-container .swal-title-custom{color:#e2e8f0}'
  ].join('');
  document.head.appendChild(style);

  /* ── Helpers ───────────────────────────────────────────────────────── */
  var DELETE_KEYWORDS  = /^(delete|delet|clear|cancel|reject|suspend|void|dispose|dispos|quarantine|quarantin|reverse|write_off|writeoff|unconfirm|wipe|remove)(?:$|_)/;
  var APPROVE_KEYWORDS = /^(approve|approv|confirm|resume|resum|activate|activ|freeze|mark|verify|verif|ready_for_delivery|hand_over_pickup|complete|complet|process|publish|match|assign|submit|receive|receiv|verify_batch|verify_expiry)(?:$|_)/;
  var SAVE_KEYWORDS    = /^(save|creat|create|update|updat|edit|add|record|make_payment|pay_now|deposit|withdraw|cash_in|cash_out|set_default|send_reminder|print_statement|pricing_settings|clone|optimize_db|do_backup|toggle|toggle_status|submit_ticket|stock_check|reserve_stock|start_preparing|prepare|mark_prepared|pack|adjust|adjustment|increase_limit|reduce_limit|update_limit)(?:$|_)/;

  function getAction(form) { return getVal(form, '_action') || getVal(form, 'action'); }
  function getVal(form, name) {
    var el = form && form.querySelector('input[name="' + name + '"]');
    return el ? (el.value || '').toLowerCase() : '';
  }

  /** Extract the original confirm message from onclick/onsubmit attributes. */
  function extractConfirmMessage(el) {
    if (!el) return null;
    var src = el.getAttribute('onclick') || el.getAttribute('onsubmit') || '';
    var m = src.match(/confirm\s*\(\s*['"](.+?)['"]\s*\)/);
    return m ? m[1] : null;
  }

  /**
   * Detect dialog type for a submit button.
   * Returns 'delete' | 'approve' | 'success' | null.
   */
  function detectType(btn) {
    if (!btn) return null;
    if (btn.dataset.swalType) return btn.dataset.swalType;
    var formEl = btn.closest('form');
    if (formEl && formEl.dataset.swalType) return formEl.dataset.swalType;

    var cls    = (btn.className || '').toLowerCase();
    var txt    = (btn.textContent || '').trim();
    var action = getAction(formEl);

    var claimed = typeof window.detectActionType === 'function' ? window.detectActionType(formEl, btn) : null;
    if (claimed && claimed !== 'deleted') return null;

    if (cls.indexOf('danger') !== -1 || cls.indexOf('-red') !== -1 ||
        /danger|red/i.test(cls) || DELETE_KEYWORDS.test(action) ||
        /delet|remov|clear|reject|cancel|suspend|undo|write.?off/i.test(txt))
      return 'delete';

    if (cls.indexOf('green') !== -1 || cls.indexOf('success') !== -1 ||
        /green|success/i.test(cls) || APPROVE_KEYWORDS.test(action) ||
        /approve|confirm|mark|verify|receive|assign|complete|resume|activate|process/i.test(txt))
      return 'approve';

    if ((btn.type === 'submit' && SAVE_KEYWORDS.test(action)) ||
        /save|creat|updat|record|add|edit|submit|post|reply|send/i.test(txt))
      return 'success';

    if (btn.type === 'submit' && formEl && formEl.dataset.swalConfirm === 'true') return 'success';
    return null;
  }

  /**
   * Pick a dialog style from the button's own classes/text. Used when a form
   * originally carried a native confirm() (so we must still confirm it, even
   * if the workflow dispatcher claims the action afterwards).
   */
  function markerType(btn) {
    if (!btn) return 'success';
    var srg = ' ' + (btn.className || '') + ' ' + (btn.textContent || '');
    if (/danger|-red|btn-red|delet|remov|clear|reject|cancel|suspend|void|write.?off|dispose|quarantine|purge|wipe/i.test(srg)) return 'delete';
    if (/green|success|approve|confirm|complete|activate|verify|receive|assign|paid|deliver/i.test(srg)) return 'approve';
    return 'success';
  }

  /* ── Dialog configurations ─────────────────────────────────────────── */
  var CFGS = {
    delete: {
      icon: 'fa-trash',
      iconBg: 'linear-gradient(135deg,#dc2626,#991b1b)',
      badgeClass: 'swal-badge-red',
      badgeText: 'Delete',
      title: 'Are you sure?',
      confirmHtml: '<i class="fas fa-trash-alt"></i> Yes, delete!',
      confirmBg: '#dc2626',
      cancelBg: '#64748b'
    },
    approve: {
      icon: 'fa-circle-check',
      iconBg: 'linear-gradient(135deg,#16a34a,#15803d)',
      badgeClass: 'swal-badge-green',
      badgeText: 'Approve',
      title: 'Confirm Action',
      confirmHtml: '<i class="fas fa-check"></i> Yes, approve!',
      confirmBg: '#16a34a',
      cancelBg: '#64748b'
    },
    success: {
      icon: 'fa-circle-check',
      iconBg: 'linear-gradient(135deg,#2563eb,#1d4ed8)',
      badgeClass: 'swal-badge-blue',
      badgeText: 'Confirm',
      title: 'Confirm Action',
      confirmHtml: '<i class="fas fa-check"></i> OK',
      confirmBg: '#2563eb',
      cancelBg: '#64748b'
    }
  };

  /**
   * Build and show a SweetAlert2 confirm dialog.
   * @param {string} type    delete | approve | success
   * @param {string} [msg]   Original confirm message to display as body text
   */
  function showConfirm(type, msg) {
    var c = CFGS[type] || CFGS.success;
    var html = '<div style="text-align:center">'
      + '<span class="swal-badge ' + c.badgeClass + '" id="swal-badge-anim">' + c.badgeText + '</span>'
      + (msg ? '<p class="swal-msg">' + msg + '</p>' : '')
      + '</div>';

    var iconEl = '<i class="fas ' + c.icon + '" style="font-size:2.4rem;color:#fff;line-height:64px"></i>';

    return Swal.fire({
      title: c.title,
      html: html,
      icon: 'warning',
      iconHtml: iconEl,
      customClass: { popup: 'swal-custom-popup', icon: 'swal2-icon-fa' },
      showCancelButton: true,
      confirmButtonColor: c.confirmBg,
      cancelButtonColor: c.cancelBg,
      confirmButtonText: c.confirmHtml,
      cancelButtonText: 'Cancel',
      reverseButtons: true,
      backdrop: 'rgba(15,23,42,0.45)',
      timerProgressBar: false,
      didOpen: function (popup) {
        // Animate icon
        var icon = popup.querySelector('.swal2-icon-fa');
        if (icon) setTimeout(function () { icon.classList.add('swal2-icon-fa-show'); }, 60);
        // Animate badge
        var badge = document.getElementById('swal-badge-anim');
        if (badge) setTimeout(function () { badge.classList.add('show'); }, 80);
      }
    });
  }

  /* ── Strip native confirm() handlers on load ───────────────────────── */
  function stripNativeConfirms() {
    document.querySelectorAll('[onclick]').forEach(function (el) {
      var h = el.getAttribute('onclick') || '';
      if (/return\s+confirm\s*\(/i.test(h)) {
        el.dataset.swalConfirmMsg = extractConfirmMessage(el);
        el.removeAttribute('onclick');
        el.dataset.swalConfirm = 'true';
      }
    });
    document.querySelectorAll('form[onsubmit]').forEach(function (form) {
      var h = form.getAttribute('onsubmit') || '';
      if (/return\s+confirm\s*\(/i.test(h)) {
        form.dataset.swalConfirmMsg = extractConfirmMessage(form);
        form.removeAttribute('onsubmit');
        form.dataset.swalConfirm = 'true';
      }
    });
  }

  /* ── Anchor (href) click interception ──────────────────────────────── */
  function handleClick(e) {
    var el = e.target.closest ? e.target.closest('a[data-swal-confirm="true"], a[data-swal-type]') : null;
    if (!el) return;
    var type = el.dataset.swalType || (/(?:delete|remove|clear|reject|cancel|undo)/i.test(el.href || '') ? 'delete' : 'success');
    var msg = el.dataset.swalConfirmMsg || null;
    e.preventDefault();
    showConfirm(type, msg).then(function (result) {
      if (result.isConfirmed) window.location.href = el.href;
    });
  }

  var submitting = false;

  /* ── Form submit interception ──────────────────────────────────────── */
  function handleSubmit(e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches('form')) return;
    if (submitting) { submitting = false; return; }
    if ((form.method || 'get').toLowerCase() === 'get') return;
    if (form.dataset.swalExclude === 'true') return;

    var submitter = e.submitter;
    if (submitter && submitter.dataset.swalExclude === 'true') return;

    /* Re-dispatch after a confirmed dialog: let ajax-interceptor handle it.
       IMPORTANT: do NOT call form.requestSubmit() from here.  This handler runs
       *during* the submit event, and the HTML spec ignores re-entrant
       requestSubmit() calls while submission events are firing, so the nested
       call is a no-op.  Calling e.preventDefault() afterwards would then cancel
       the only real submission and silently drop the user's action (the "form
       shows, but Create/Submit does nothing" bug).  Just clear the flag and
       return without preventing default so the event bubbles on to
       ajax-interceptor (or falls back to a normal native submit). */
    if (form.dataset.swalDone === 'true') {
      delete form.dataset.swalDone;
      return;
    }

    var type = null;
    if (submitter) type = submitter.dataset.swalType || detectType(submitter);
    if (!type && form.dataset.swalType) type = form.dataset.swalType;

    /* Forms that explicitly carried a native confirm() always get our dialog
       (workflow claims included) — the app clearly wants confirmation here. */
    if (!type &&
        (form.dataset.swalConfirm === 'true' || (submitter && submitter.dataset.swalConfirm === 'true')) &&
        submitter && submitter.type === 'submit') {
      type = markerType(submitter);
    }

    if (!type) return;
    e.preventDefault();

    // Extract message from button or form
    var msg = null;
    if (submitter) msg = submitter.dataset.swalConfirmMsg || null;
    if (!msg && form.dataset.swalConfirmMsg) msg = form.dataset.swalConfirmMsg;

    var btn = submitter;

    /* Show the dialog BEFORE relying on it. The native submit was already
       cancelled above, so if the confirmation UI cannot be shown we must fall
       back to a real submission — never silently drop the user's action. */
    var dialog;
    try {
      dialog = showConfirm(type, msg);
    } catch (err) {
      submitNatively(form, btn);
      return;
    }

    Promise.resolve(dialog).then(function (result) {
      if (!result || !result.isConfirmed) return;
      if (btn && btn.name && btn.value) {
        var existing = form.querySelector('input[data-swal-btn="' + btn.name + '"]');
        if (existing) existing.value = btn.value;
        else {
          var inp = document.createElement('input');
          inp.type = 'hidden'; inp.name = btn.name; inp.value = btn.value;
          inp.dataset.swalBtn = btn.name;
          form.appendChild(inp);
        }
      }
      if (btn && btn.formAction) form.setAttribute('action', btn.formAction);
      if (btn && btn.formMethod) form.method = btn.formMethod;
      form.dataset.swalConfirmed = 'true';
      form.dataset.swalDone = 'true';
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(btn || undefined);
      } else {
        form.submit();
        submitting = false;
      }
    })['catch'](function () {
      // Confirmation flow broke after the submit was cancelled: submit for real.
      submitNatively(form, btn);
    });
  }

  /**
   * Last-resort real submission, used only when nothing has been sent to the
   * server yet (the ajax-interceptor is bypassed by form.submit()).
   */
  function submitNatively(form, btn) {
    delete form.dataset.swalConfirmed;
    delete form.dataset.swalDone;
    submitting = false;
    try { form.submit(); } catch (err) { console.error('[swal.js] submit failed', err); }
  }

  /* ── Init ──────────────────────────────────────────────────────────── */
  function init() {
    if (typeof Swal === 'undefined') {
      console.warn('[swal.js] SweetAlert2 not loaded — confirmations disabled.');
      return;
    }
    stripNativeConfirms();
    document.addEventListener('submit', handleSubmit, true);
    document.addEventListener('click', handleClick, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
