/* ============================================================
   CashPe - Global JS: theme toggle, drawer, toasts, clipboard,
   confirm modal. No dependencies, no emoji.
   ============================================================ */
(function () {
  'use strict';

  /* ---------- Dark mode toggle ---------- */
  var darkToggle = document.getElementById('darkToggle');
  if (darkToggle) {
    darkToggle.addEventListener('click', function () {
      var html = document.documentElement;
      var isDark = html.classList.toggle('dark');
      try { localStorage.setItem('cashpe-theme', isDark ? 'dark' : 'light'); } catch (e) {}
    });
  }

  /* ---------- Sidebar drawer (mobile) ---------- */
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sidebarOverlay');
  var openBtn = document.getElementById('sidebarToggle');
  var closeBtn = document.getElementById('sidebarClose');

  function setDrawer(open) {
    if (!sidebar || !overlay) return;
    sidebar.classList.toggle('-translate-x-full', !open);
    overlay.classList.toggle('hidden', !open);
    document.body.style.overflow = open ? 'hidden' : '';
  }
  if (openBtn) openBtn.addEventListener('click', function () { setDrawer(true); });
  if (closeBtn) closeBtn.addEventListener('click', function () { setDrawer(false); });
  if (overlay) overlay.addEventListener('click', function () { setDrawer(false); });

  /* ---------- Toasts ---------- */
  var ICONS = {
    success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 shrink-0"><circle cx="12" cy="12" r="10"/><polyline points="8.5 12.5 11.5 15.5 15.5 9.5"/></svg>',
    error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 shrink-0"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
  };

  function toast(message, type) {
    type = type || 'info';
    var root = document.getElementById('toastRoot');
    if (!root) return;
    var el = document.createElement('div');
    el.className = 'toast toast-' + type;
    el.setAttribute('role', 'status');
    el.innerHTML = (ICONS[type] || ICONS.info) + '<span></span>';
    el.querySelector('span:last-child').textContent = message;
    root.appendChild(el);
    window.setTimeout(function () {
      el.style.transition = 'opacity .25s ease, transform .25s ease';
      el.style.opacity = '0';
      el.style.transform = 'translateY(6px)';
      window.setTimeout(function () { el.remove(); }, 260);
    }, 4200);
  }
  window.toast = toast;

  /* ---------- Clipboard ---------- */
  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy') ? resolve() : reject(new Error('copy failed')); }
      catch (err) { reject(err); }
      document.body.removeChild(ta);
    });
  }
  window.copyText = copyText;

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-copy]');
    if (!btn) return;
    ev.preventDefault();
    var text = btn.getAttribute('data-copy') || '';
    copyText(text).then(function () {
      toast('Copied to clipboard', 'success');
    }).catch(function () {
      toast('Could not copy. Please copy manually.', 'error');
    });
  });

  /* ---------- Shared confirm modal ---------- */
  var modal = document.getElementById('confirmModal');
  var titleEl = document.getElementById('confirmTitle');
  var messageEl = document.getElementById('confirmMessage');
  var acceptBtn = document.getElementById('confirmAccept');
  var pendingAction = null;

  function openModal(title, message, onAccept) {
    if (!modal) { if (onAccept) onAccept(); return; }
    if (titleEl) titleEl.textContent = title || 'Are you sure?';
    if (messageEl) messageEl.textContent = message || 'This action cannot be undone.';
    pendingAction = typeof onAccept === 'function' ? onAccept : null;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = '';
    pendingAction = null;
  }
  window.cashpeConfirm = openModal;

  if (acceptBtn) {
    acceptBtn.addEventListener('click', function () {
      var action = pendingAction;
      closeModal();
      if (action) action();
    });
  }
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-confirm-cancel]')) closeModal();
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') closeModal();
  });

  /* ---------- data-confirm: intercept submits / clicks ---------- */
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.matches || !form.hasAttribute('data-confirm')) return;
    ev.preventDefault();
    var message = form.getAttribute('data-confirm') || 'Are you sure?';
    var title = form.getAttribute('data-confirm-title') || 'Please confirm';
    openModal(title, message, function () {
      form.removeAttribute('data-confirm');
      form.submit();
    });
  }, true);

  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-confirm-click]');
    if (!el) return;
    ev.preventDefault();
    var message = el.getAttribute('data-confirm-click') || 'Are you sure?';
    var title = el.getAttribute('data-confirm-title') || 'Please confirm';
    var href = el.getAttribute('href');
    openModal(title, message, function () {
      if (href) window.location.href = href;
    });
  });
})();
