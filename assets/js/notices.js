/* ==========================================================================
   FlatMate  |  Notices
   --------------------------------------------------------------------------
   The announcement board: category filters, unread tracking, and posting.

   Filtering and searching happen on the client over the already-loaded page
   of notices. The API caps a single feed at 100 rows, which is far more than
   a noticeboard holds in practice, so paging would be dead weight here.
   ========================================================================== */

(() => {
  'use strict';

  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  let me      = null;
  let notices = [];

  const isAdmin = () => me?.role === 'admin';

/* ------------------------------------------------------------------ */
/*  Rendering                                                          */
/* ------------------------------------------------------------------ */

  /* NoticeBoard::CATEGORY_META uses Bootstrap semantic names; the theme uses
   * its own token names. Map once so the markup stays on theme vocabulary. */
  const CAT_CLASS = {
    primary: 'ok',
    success: 'ok',
    warning: 'warn',
    danger: 'bad',
    info:    'info',
  };
  const catClass = (n) => CAT_CLASS[n.category_class] || 'muted';

  function noticeCard(n) {
    const actions = [];
    if (n.is_mine || isAdmin()) {
      actions.push(`<button class="btn btn-sm btn-outline-danger" data-delete="${n.id}"
        data-confirm-title="Delete this notice?"
        data-confirm-text="It disappears for everyone. This cannot be undone."
        data-confirm-ok="Delete" data-confirm-danger="1">
        <i class="bi bi-trash"></i></button>`);
    }
    if (isAdmin()) {
      actions.push(`<button class="btn btn-sm btn-outline-secondary" data-pin="${n.id}"
        title="${n.is_pinned_active ? 'Unpin' : 'Pin to the top'}"
        aria-pressed="${n.is_pinned_active ? 'true' : 'false'}">
        <i class="bi bi-pin-angle${n.is_pinned_active ? '-fill' : ''}"></i></button>`);
    }

    return `
      <div class="fm-notice ${n.is_read ? 'read' : 'unread'} ${n.is_pinned_active ? 'pinned' : ''}"
           data-notice="${n.id}">
        <span class="fm-notice-icon ${catClass(n)}">
          <i class="bi ${esc(n.icon)}"></i>
        </span>

        <div class="fm-notice-main">
          <div class="fm-notice-title">
            ${esc(n.title)}
            <span class="fm-pill ${catClass(n)}">${esc(n.category_label)}</span>
            ${n.is_pinned_active ? '<span class="fm-pill info">pinned</span>' : ''}
          </div>
          <div class="fm-notice-meta">
            ${esc(n.author_name || 'Someone')} &middot; ${esc(n.ago || '')}
            &middot; ${esc(n.audience_label)}
          </div>
          <p class="fm-notice-excerpt">${esc(n.excerpt || '')}</p>
        </div>

        <div class="fm-notice-end d-flex align-items-center gap-1">
          ${n.is_read ? '' : '<span class="fm-pill warn">new</span>'}
          ${actions.join('')}
        </div>
      </div>`;
  }

  function visible() {
    const q = ($('[data-notice-search]')?.value || '').toLowerCase();
    const cat = $('.active')?.dataset?.noticeCat ?? '';
    const unreadOnly = $('[data-notice-unread]')?.checked;

    return notices.filter((n) => {
      if (cat && n.category !== cat) return false;
      if (unreadOnly && n.is_read) return false;
      if (!q) return true;
      return (n.title || '').toLowerCase().includes(q)
          || (n.body || '').toLowerCase().includes(q)
          || (n.author_name || '').toLowerCase().includes(q);
    });
  }

  function render() {
    const list = visible();
    const host = $('[data-notices]');

    if (!notices.length) {
      host.innerHTML = `<div class="fm-card"><div class="fm-empty">
        <i class="bi bi-megaphone"></i><h3>Nothing posted yet</h3>
        <p>Notices about maintenance, bills or guests show up here.</p>
      </div></div>`;
      return;
    }

    // Pinned first, then newest.
    const sorted = [...list].sort((a, b) => {
      if (a.is_pinned_active !== b.is_pinned_active) return a.is_pinned_active ? -1 : 1;
      return (b.id ?? 0) - (a.id ?? 0);
    });

    host.innerHTML = sorted.length
      ? `<div class="fm-card">${sorted.map(noticeCard).join('')}</div>`
      : `<div class="fm-card"><div class="fm-empty">
           <i class="bi bi-filter"></i><h3>No matches</h3>
           <p>Nothing matches these filters.</p>
         </div></div>`;

    const unread = notices.filter((n) => !n.is_read).length;
    $('[data-mark-all]')?.classList.toggle('d-none', unread === 0);
  }

  async function load() {
    notices = await App.guard(() => API.get('notice.list', { limit: 100 })) || [];
    render();
  }

  /* ------------------------------------------------------------------ */
  /*  Detail                                                             */
  /* ------------------------------------------------------------------ */

  async function openNotice(id) {
    const n = await App.guard(() => API.get('notice.find', { id }));
    if (!n || !n.id) return;

    $('[data-detail-title]').textContent = n.title;
    $('[data-detail-body]').innerHTML = `
      <div class="d-flex align-items-start gap-3 mb-3">
        <span class="fm-notice-icon ${catClass(n)}"><i class="bi ${esc(n.icon)}"></i></span>
        <div>
          <div class="d-flex align-items-center gap-2 mb-1">
            <span class="fm-pill ${catClass(n)}">${esc(n.category_label)}</span>
            <span class="fm-pill muted">${esc(n.audience_label)}</span>
          </div>
          <div class="text-faint" style="font-size:.76rem">
            ${esc(n.author_name || 'Someone')} &middot; ${esc(n.ago || '')}
            ${n.view_count ? ` &middot; ${n.view_count} view${n.view_count === 1 ? '' : 's'}` : ''}
          </div>
        </div>
      </div>

      ${n.body
        ? `<p style="white-space:pre-wrap" class="mb-0">${esc(n.body)}</p>`
        : '<p class="text-faint mb-0">No further details were given.</p>'}`;

    App.openModal($('#noticeDetail'));

    // Opening it is what marks it read.
    if (!n.is_read) {
      await App.guard(() => API.post('notice.read', { id }));
      const local = notices.find((x) => x.id === id);
      if (local) { local.is_read = true; local.read_at = new Date().toISOString(); }
      render();
    }
  }

  /* ------------------------------------------------------------------ */
  /*  Compose form                                                       */
  /* ------------------------------------------------------------------ */

  async function initForm() {
    const form = $('[data-form="notice"]');
    if (!form) return;

    const bucket = await App.guard(() => API.get('resident.roster')) || {};
    $('[data-rooms]', form).innerHTML = (bucket.rooms || []).map((r) =>
      `<option value="${r.id}">${esc(r.code)} — ${esc(r.name || '')}</option>`).join('');
    $('[data-groups]', form).innerHTML = (bucket.duty_groups || []).map((g) =>
      `<option value="${g.id}">${esc(g.name)}</option>`).join('');

    form.addEventListener('change', (ev) => {
      if (ev.target.name !== 'audience') return;
      const mode = ev.target.value;
      $$('[data-when]', form).forEach((b) =>
        b.classList.toggle('d-none', b.dataset.when !== mode));
    });

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      App.clearErrors(form);
      const payload = Object.fromEntries(new FormData(form).entries());

      const submit = form.querySelector('[type="submit"]');
      submit.disabled = true;
      await App.guard(async () => {
        await API.post('notice.create', payload);
        App.toast('Notice posted.', 'ok');
        form.reset();
        $$('[data-when]', form).forEach((b) => b.classList.add('d-none'));
        App.closeModal($('#newNotice'));
        await load();
      }, {
        onError: (err) => {
          if (err instanceof API.ApiError && err.code === 'validation_failed') {
            App.showErrors(form, err.details);
          }
        },
      });
      submit.disabled = false;
    });
  }

  /* ------------------------------------------------------------------ */
  /*  Wiring                                                             */
  /* ------------------------------------------------------------------ */

  function wire() {
    let timer = null;
    $('[data-notice-search]')?.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(render, 200);
    });

    $$('[data-notice-cat]').forEach((btn) => {
      btn.addEventListener('click', () => {
        $$('[data-notice-cat]').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        render();
      });
    });

    $('[data-notice-unread]')?.addEventListener('change', render);

    // Open a notice
    on(document, 'click', '[data-notice]', (ev, el) => {
      if (ev.target.closest('button')) return;
      openNotice(Number(el.dataset.notice));
    });

    // Mark everything read
    on(document, 'click', '[data-mark-all]', async (ev, el) => {
      el.disabled = true;
      await App.guard(async () => {
        const res = await API.post('notice.read_all', {});
        App.toast(`${res?.marked ?? 0} notice(s) marked read.`, 'ok');
        await load();
      });
      el.disabled = false;
    });

    // Pin / unpin
    on(document, 'click', '[data-pin]', async (ev, el) => {
      const id = Number(el.dataset.pin);
      const current = notices.find((n) => n.id === id);
      el.disabled = true;
      await App.guard(async () => {
        await API.post('notice.pin', { id, pinned: !current?.is_pinned_active });
        await load();
      });
      el.disabled = false;
    });

    // Delete
    on(document, 'click', '[data-delete]', async (ev, el) => {
      await App.guard(async () => {
        await API.post('notice.delete', { id: Number(el.dataset.delete) });
        App.toast('Notice deleted.', 'ok');
        App.closeModal($('#noticeDetail'));
        await load();
      });
    });
  }

  document.addEventListener('DOMContentLoaded', async () => {
    me = await App.guard(() => API.me());
    if (!me) return;
    wire();
    await initForm();
    await load();
  });
})();