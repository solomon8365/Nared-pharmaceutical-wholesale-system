/**
 * Standardized SweetAlert2 Action Notifications — NARED PHARMACON
 *
 * Rich animated result modals for workflow actions:
 *   approved  → green circle-check, animated badge + descriptive text
 *   paid      → green coins, animated badge + amount context
 *   packed    → blue box-open, animated badge
 *   delivered → green truck, animated badge
 *   deleted   → red trash, animated badge
 *
 * Features:
 *   • Animated icon entrance (scale + bounce)
 *   • Staggered badge + text fade-in
 *   • Bottom-right "OK" button with accent colour
 *   • Dark-mode aware
 */
(function () {
  'use strict';

  /* ── Styling (injected once) ───────────────────────────────────────── */
  var styleEl = document.createElement('style');
  styleEl.textContent = [
    '.iffn-modal{text-align:center;overflow:visible}',
    '.iffn-badge{display:inline-block;padding:5px 18px;border-radius:999px;font-size:.73rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;line-height:1.2;opacity:0;transform:translateY(8px) scale(0.95);transition:opacity .3s .1s ease,transform .3s .1s ease}',
    '.iffn-badge.show{opacity:1;transform:translateY(0) scale(1)}',
    '.iffn-badge-green{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;box-shadow:0 2px 12px rgba(22,163,74,0.3)}',
    '.iffn-badge-blue{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 2px 12px rgba(37,99,235,0.3)}',
    '.iffn-badge-red{background:linear-gradient(135deg,#dc2626,#991b1b);color:#fff;box-shadow:0 2px 12px rgba(220,38,38,0.3)}',
    '.iffn-badge-amber{background:linear-gradient(135deg,#d97706,#b45309);color:#fff;box-shadow:0 2px 12px rgba(217,119,6,0.3)}',
    '.iffn-msg{margin:8px 0 0;color:#475569;font-size:.92rem;line-height:1.5;max-width:300px;margin-left:auto;margin-right:auto}',
    '.swal2-icon.swal2-icon-fa-anim{border:none !important;background:none !important;width:64px !important;height:64px !important;margin:8px auto 12px !important;opacity:0;transform:scale(0) rotate(-10deg);transition:opacity .35s ease,transform .45s cubic-bezier(.17,.89,.32,1.28);animation:none !important}',
    '.swal2-icon.swal2-icon-fa-anim.show{opacity:1;transform:scale(1) rotate(0)}',
    '.swal2-icon.swal2-icon-fa-anim svg{display:none}',
    '.swal2-icon.swal2-icon-fa-anim i{font-size:2.4rem;line-height:64px;color:#fff}',
    '.swal2-actions .swal2-styled{border-radius:10px !important;font-weight:700 !important;padding:10px 24px !important;font-size:.85rem !important}',
    '.swal2-actions .swal2-styled:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(0,0,0,0.18)}',
    'body.dark-mode .iffn-msg{color:#94a3b8}'
  ].join('');
  document.head.appendChild(styleEl);

  /* ── Action-type detection ─────────────────────────────────────────── */
  function getVal(form, name) {
    var el = form && form.querySelector('input[name="' + name + '"]');
    return el ? (el.value || '').toLowerCase() : '';
  }

  var APPROVE_KEYWORDS = /^(approve|approv|confirm|resume|resum|activate|activ|verify|verif|complete|complet|process|publish|match|assign|submit|receive|receiv|mark_approved|ready_for_delivery|hand_over_pickup)$/;
  var PAID_KEYWORDS     = /(^|_)(paid|payment|pay_now|record_payment|set_paid|mark_paid|make_payment|deposit|settle|settlement)(_|$)/;
  var PACK_KEYWORDS     = /^(pack|packed|mark_packed|prepare|prepar|mark_prepared|start_preparing|stock_check|reserve_stock|hand_over)$/;
  var DELIVER_KEYWORDS  = /^(deliver|deliver|delivered|mark_delivered|complete_delivery|out_for_delivery|fulfill)$/;
  var DELETE_KEYWORDS   = /^(delete|delet|clear|cancel|reject|suspend|void|dispose|dispos|quarantine|quarantin|reverse|write_off|writeoff|unconfirm|wipe|remove|deactivate|inactiv)$/;

  function getAction(form) { return getVal(form, '_action') || getVal(form, 'action'); }

  function detectActionType(form, btn) {
    var override = btn && btn.dataset.actionType;
    if (!override && form) override = form.dataset.actionType;
    if (override) return CONFIGS[override] ? override : null;

    var action = getAction(form);
    var txt = ((btn && btn.textContent) || '').trim().toLowerCase();

    if (DELETE_KEYWORDS.test(action) || /delet|remov|clear|reject|cancel|suspend|write.?off|deactiv/i.test(txt)) return 'deleted';
    if (PAID_KEYWORDS.test(action) || /paid|payment|pay_now|settle/i.test(txt)) return 'paid';
    if (DELIVER_KEYWORDS.test(action) || /deliver/i.test(txt)) return 'delivered';
    if (PACK_KEYWORDS.test(action) || /pack|prepare/i.test(txt)) return 'packed';
    if (APPROVE_KEYWORDS.test(action) || /approve|confirm|complete|activate|verify|receive|assign/i.test(txt)) return 'approved';
    return null;
  }

  /* ── Modal configs ─────────────────────────────────────────────────── */
  var CONFIGS = {
    approved: {
      icon: 'fa-circle-check',
      iconBg: 'linear-gradient(135deg,#16a34a,#15803d)',
      iconColor: '#16a34a',
      badgeClass: 'iffn-badge-green',
      badgeText: '<i class="fas fa-check" style="margin-right:4px"></i> APPROVED',
      msg: 'Successfully approved!',
      confirmBg: '#2563eb'
    },
    paid: {
      icon: 'fa-coins',
      iconBg: 'linear-gradient(135deg,#16a34a,#15803d)',
      iconColor: '#16a34a',
      badgeClass: 'iffn-badge-green',
      badgeText: '<i class="fas fa-coins" style="margin-right:4px"></i> PAID',
      msg: 'Payment confirmed successfully!',
      confirmBg: '#2563eb'
    },
    packed: {
      icon: 'fa-box-open',
      iconBg: 'linear-gradient(135deg,#2563eb,#1d4ed8)',
      iconColor: '#2563eb',
      badgeClass: 'iffn-badge-blue',
      badgeText: '<i class="fas fa-box-open" style="margin-right:4px"></i> PACKED',
      msg: 'Order successfully packed and prepared!',
      confirmBg: '#2563eb'
    },
    delivered: {
      icon: 'fa-truck',
      iconBg: 'linear-gradient(135deg,#16a34a,#15803d)',
      iconColor: '#16a34a',
      badgeClass: 'iffn-badge-green',
      badgeText: '<i class="fas fa-truck" style="margin-right:4px"></i> DELIVERED',
      msg: 'Order successfully delivered!',
      confirmBg: '#2563eb'
    },
    deleted: {
      icon: 'fa-trash',
      iconBg: 'linear-gradient(135deg,#dc2626,#991b1b)',
      iconColor: '#dc2626',
      badgeClass: 'iffn-badge-red',
      badgeText: '<i class="fas fa-trash" style="margin-right:4px"></i> DELETED',
      msg: 'Record successfully deleted!',
      confirmBg: '#2563eb'
    }
  };

  /**
   * Show the standardized result modal for a workflow type.
   * Returns a Promise resolving with { isConfirmed }.
   */
  function notifyAction(type) {
    var cfg = CONFIGS[type] || CONFIGS.approved;
    if (typeof Swal === 'undefined') return Promise.resolve({ isConfirmed: true, dismiss: null });

    var iconHtml = '<i class="fas ' + cfg.icon + '" style="font-size:2.4rem;color:#fff;line-height:64px"></i>';
    var html = '<div class="iffn-modal">'
      + '<span class="iffn-badge ' + cfg.badgeClass + '" id="iffn-badge-anim">' + cfg.badgeText + '</span>'
      + '<p class="iffn-msg">' + cfg.msg + '</p>'
      + '</div>';

    return Swal.fire({
      title: ' ',
      html: html,
      icon: 'warning',
      iconHtml: iconHtml,
      customClass: { popup: 'swal-custom-popup', icon: 'swal2-icon-fa-anim', actions: 'iffn-actions' },
      iconColor: cfg.iconColor,
      confirmButtonColor: cfg.confirmBg,
      confirmButtonText: 'OK',
      showConfirmButton: true,
      showCancelButton: false,
      focusConfirm: true,
      backdrop: 'rgba(15,23,42,0.45)',
      didOpen: function (el) {
        // Animate icon
        var icon = el.querySelector('.swal2-icon-fa-anim');
        if (icon) setTimeout(function () { icon.classList.add('show'); }, 60);
        // Animate badge
        var badge = document.getElementById('iffn-badge-anim');
        if (badge) setTimeout(function () { badge.classList.add('show'); }, 100);
        // Right-align OK button
        var actions = el.querySelector('.swal2-actions');
        if (actions) actions.style.justifyContent = 'flex-end';
      }
    });
  }

  window.actionNotify = notifyAction;
  window.detectActionType = detectActionType;
  window.ACTION_ICONS = {};
})();
