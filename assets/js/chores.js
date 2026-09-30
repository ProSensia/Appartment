/* ==========================================================================
   FlatMate  |  Chores board
   --------------------------------------------------------------------------
   Board, my duties, admin verification and rotation-rule management.

   The rotation itself is computed server-side and is a strict cyclic sequence
   per (pool, frequency, offset) -- this file never re-derives it, it only
   shows the result.
   ========================================================================== */

(() => {
  'use strict';

  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  let me = null;
  let from = null;
  let to   = null;

  const isoToday = () => new Date().toISOString().slice(0, 10);
  const shiftIso = (iso, days) => {
    const d = new Date(`${iso}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
  };

  const sectionTitle = (text, kind, count) =>
    `<p class="fm-section-title">${esc(text)} <span class="fm-pill ${kind}">${count}</span></p>`;

  /* ------------------------------------------------------------------ */
  /*  Task row                                                           */
  /* ------------------------------------------------------------------ */

  function taskRow(t) {
    const mine = t.is_mine;
    const cls  = ['fm-chore', mine ? 'mine' : 'other', t.is_past && t.status === 'pending' ? 'past' : ''].join(' ');

    const iconCls = { done: 'done', verified: 'done', skipped: 'skipped' }[t.status] || '';
    const statusIcon = { done: 'bi-check-lg', verified: 'bi-patch-check-fill', skipped: 'bi-dash-lg' }[t.status];

    let actions = '';
    if (t.status === 'pending' && mine) {
      actions = `
        <button class="btn btn-sm btn-primary" data-complete="${t.id}">Done</button>
        <button class="btn btn-sm btn-outline-secondary" data-skip="${t.id}" title="Skip with a reason">
          <i class="bi bi-skip-forward"></i>
        </button>`;
    }
    if (t.status === 'done' && me?.role === 'admin') {
      actions = `<button class="btn btn-sm btn-success" data-verify="${t.id}">Verify</button>`;
    }
    if (t.status === 'pending' && !mine && me?.role === 'admin') {
      actions = `<button class="btn btn-sm btn-outline-secondary" data-verify="${t.id}" hidden></button>`;
    }

    const who = t.assignee_name
      ? `${mine ? 'You' : esc(t.assignee_name)}`
      : '<span class="text-danger">Unassigned</span>';

    const note = t.notes
      ? `<div class="fm-chore-meta"><i class="bi bi-chat-left-quote"></i> ${esc(t.notes)}</div>`
      : '';

    return `
      <div class="${cls}" data-task="${t.id}">
        <span class="fm-chore-icon ${iconCls}"><i class="bi ${esc(t.icon)}"></i></span>
        <div class="fm-chore-main">
          <div class="fm-chore-title">
            ${esc(t.area_name)}
            ${t.status !== 'pending' ? `<i class="bi ${statusIcon} text-success"></i>` : ''}
            ${t.is_overdue ? '<span class="fm-pill bad">overdue</span>' : ''}
          </div>
          <div class="fm-chore-meta">
            ${who}
            &middot; ${esc(t.day_label)}, ${esc(Fmt.date(t.task_date))}
            &middot; <span class="fm-pill ${t.badge_class === 'secondary' ? 'muted' : t.badge_class}">${esc(t.badge)}</span>
          </div>
          ${note}
        </div>
        <div class="fm-chore-end d-flex gap-1">${actions}</div>
      </div>`;
  }

  /* ------------------------------------------------------------------ */
  /*  Board                                                              */
  /* ------------------------------------------------------------------ */

  async function loadBoard() {
    const host = $('[data-board]');
    host.innerHTML = '<div class="fm-card p-3"><div class="fm-skeleton" style="height:9rem"></div></div>';

    await App.guard(async () => {
      const tasks = await API.choreBoard(from, to);
      const list = tasks || [];

      if (!list.length) {
        host.innerHTML = `<div class="fm-card"><div class="fm-empty">
          <i class="bi bi-calendar-x"></i><h3>Nothing scheduled</h3>
          <p>No chores are generated for ${esc(Fmt.date(from))} – ${esc(Fmt.date(to))}.</p>
        </div></div>`;
        return;
      }

      // Group by date.
      const byDate = new Map();
      list.forEach((t) => {
        if (!byDate.has(t.task_date)) byDate.set(t.task_date, []);
        byDate.get(t.task_date).push(t);
      });

      host.innerHTML = Array.from(byDate.entries()).map(([date, rows]) => {
        const mineCount = rows.filter((t) => t.is_mine).length;
        return `
        <div class="fm-card mb-3">
          <div class="fm-card-head">
            <h2>
              ${esc(Fmt.date(date, { weekday: 'long' }))}
              <span class="text-faint fw-normal" style="font-size:.82rem">
                ${esc(Fmt.date(date))}${date === isoToday() ? ' · today' : ''}
              </span>
            </h2>
            <div class="fm-end">
              ${mineCount ? `<span class="fm-pill ok">${mineCount} yours</span>` : ''}
              <span class="fm-pill muted">${rows.length} task${rows.length === 1 ? '' : 's'}</span>
            </div>
          </div>
          ${rows.map(taskRow).join('')}
        </div>`;
      }).join('');
    });
  }

  /* ------------------------------------------------------------------ */
  /*  My duties + fairness                                               */
  /* ------------------------------------------------------------------ */

  async function loadMine() {
    const days = Number($('[data-mine-days]')?.value || 14);
    const host = $('[data-mine]');

    await App.guard(async () => {
      // forUser() returns buckets, not a flat list: overdue, today, upcoming.
      const d = (await API.choreMine(days)) || {};
      const overdue = d.overdue || [];
      const today   = d.today || [];
      const upcoming = d.upcoming || [];

      // Each heading must be followed by its OWN tasks, so the buckets are
      // zipped with their labels rather than concatenated into one list.
      const groups = [
        ['Overdue', 'bad', overdue],
        ['Today', 'ok', today],
        ['Coming up', 'muted', upcoming],
      ].filter(([, , list]) => list.length);

      const sections = groups.map(([label, kind, list]) =>
        sectionTitle(label, kind, list.length) + list.map(taskRow).join('')
      ).join('');

      host.innerHTML = sections
        ? `<div class="fm-card">${sections}</div>`
        : `<div class="fm-empty">
             <i class="bi bi-cup-hot"></i><h3>No duties in this window</h3>
             <p>Nothing on your rotation for the next ${days} days.</p>
           </div>`;
    });

    const fair = await App.guard(() => API.get('chore.fairness', { days }));
    if (!fair) return;

    const rows = fair.rows || [];
    const max  = Math.max(...rows.map((r) => r.total), 1);

    $('[data-fairness]').innerHTML = rows.length
      ? `<p class="fm-section-title">Last ${fair.window_days} days &middot; ${fair.total_tasks} tasks</p>` +
        rows.map((r) => `
          <div class="fm-fair-row">
            <span class="fm-avatar sm" style="background:${esc(r.avatar)}">${esc(Fmt.initials(r.full_name))}</span>
            <div class="fm-fair-name fm-truncate" title="${esc(r.full_name)}">
              ${esc(r.full_name)}${r.user_id === me.id ? ' (you)' : ''}
            </div>
            <div class="fm-fair-track">
              <div class="fm-fair-fill ${r.verdict === 'over' ? 'over' : (r.user_id === me.id ? 'me' : '')}"
                   style="width:${Math.round((r.total / max) * 100)}%"></div>
            </div>
            <div class="fm-fair-val">${r.completed}/${r.total}</div>
          </div>`).join('') +
        `<p class="text-faint mt-2 mb-0" style="font-size:.75rem">
           Fair share would be ${esc(String(fair.ideal_each))} tasks each
           (${esc(String(fair.window?.from || ''))} &rarr; ${esc(String(fair.window?.to || ''))}).
         </p>`
      : '<p class="text-muted-2 mb-0" style="font-size:.85rem">No chores in this window yet.</p>';
  }

  /* ------------------------------------------------------------------ */
  /*  Verify queue (admin)                                               */
  /* ------------------------------------------------------------------ */

  async function loadVerify() {
    const host = $('[data-verify]');
    if (!host) return;

    await App.guard(async () => {
      const dash = await API.get('dashboard');
      const rows = dash.chores?.needs_verify || [];
      const badge = $('[data-verify-count]');
      if (badge) {
        badge.textContent = rows.length;
        badge.classList.toggle('d-none', rows.length === 0);
      }

      host.innerHTML = rows.length
        ? `<div>${rows.map((t) => `
            <div class="fm-resident">
              <span class="fm-avatar" style="background:${esc(t.assignee_avatar || '#64748b')}">
                ${esc(Fmt.initials(t.assignee_name))}
              </span>
              <div class="fm-resident-main">
                <div class="fm-resident-name">${esc(t.area_name)}</div>
                <div class="fm-resident-meta">
                  ${esc(t.assignee_name || 'Unassigned')} &middot; ${esc(Fmt.date(t.task_date))}
                  ${t.notes ? `<br><i class="bi bi-chat-left-quote"></i> ${esc(t.notes)}` : ''}
                </div>
              </div>
              <button class="btn btn-sm btn-success" data-verify="${t.id}">
                <i class="bi bi-patch-check"></i> Verify
              </button>
            </div>`).join('')}</div>`
        : `<div class="fm-empty"><i class="bi bi-patch-check"></i>
             <h3>Nothing to verify</h3><p>Every completed chore has been confirmed.</p></div>`;
    });
  }

  /* ------------------------------------------------------------------ */
  /*  Rotation rules (admin)                                             */
  /* ------------------------------------------------------------------ */

  function areaCard(a) {
    const preview = (a.upcoming || []).slice(0, 6);
    return `
      <div class="fm-card">
        <div class="fm-card-head">
          <span class="fm-chore-icon"><i class="bi ${esc(a.icon)}"></i></span>
          <div>
            <h2>${esc(a.name)}</h2>
            <p class="fm-card-sub">${esc(a.frequency_label)} &middot; ${esc(a.scope_label)}</p>
          </div>
          <div class="fm-end">
            <button class="btn btn-sm btn-outline-secondary" data-rotate="${a.id}" title="Shift the cycle">
              <i class="bi bi-arrow-repeat"></i>
            </button>
            <button class="btn btn-sm btn-outline-secondary" data-retire="${a.id}"
                    data-confirm-title="Retire this area?"
                    data-confirm-text="${esc(a.name)} will stop being scheduled. Upcoming pending tasks are cancelled. History is kept."
                    data-confirm-ok="Retire" data-confirm-danger="1">
              <i class="bi bi-archive"></i>
            </button>
          </div>
        </div>
        <div class="fm-card-body">
          <div class="d-flex flex-wrap gap-3 mb-2">
            <span class="fm-pill muted"><i class="bi bi-people"></i> ${a.pool_size} in the pool</span>
            <span class="fm-pill muted"><i class="bi bi-calendar3"></i> ${esc(a.weekday_list?.label || '')}</span>
            <span class="fm-pill muted"><i class="bi bi-star"></i> ${a.points} pts</span>
            <span class="fm-pill muted"><i class="bi bi-arrow-left-right"></i> offset ${a.rotation_offset}</span>
            ${a.is_active ? '' : '<span class="fm-pill bad">retired</span>'}
          </div>
          ${preview.length
            ? `<div class="fm-section-title">Next up</div>
               <div class="fm-rot-preview">${preview.map((p) => `
                 <span class="fm-rot-chip ${p.user_id === me.id ? 'me' : ''}">
                   ${esc(p.full_name || '—')} · ${esc(Fmt.date(p.date, { weekday: 'short', day: 'numeric' }))}
                 </span>`).join('')}</div>`
            : '<p class="text-faint mb-0" style="font-size:.8rem">No upcoming tasks — generate dates to fill the board.</p>'}
          ${a.description ? `<p class="text-muted-2 mt-2 mb-0" style="font-size:.8rem">${esc(a.description)}</p>` : ''}
        </div>
      </div>`;
  }

  async function loadAreas() {
    const host = $('[data-areas]');
    if (!host) return;

    await App.guard(async () => {
      const areas = (await API.get('chore.areas')) || [];

      // Look ahead per area so the card can show the rotation order.
      const withPreview = await Promise.all(areas.map(async (a) => {
        const upcoming = await App.guard(() => API.get('chore.upcoming', { area_id: a.id, count: 7 }))
          .catch(() => []);
        return { ...a, upcoming: upcoming || [] };
      }));

      host.innerHTML = withPreview.length
        ? withPreview.map(areaCard).join('')
        : `<div class="fm-card"><div class="fm-empty">
             <i class="bi bi-sliders"></i><h3>No rotation rules yet</h3>
             <p>Create an area like "Kitchen floor" and FlatMate will build a fair rotation for you.</p>
           </div></div>`;
    });
  }

  async function initAreaForm() {
    const form = $('[data-form="area"]');
    if (!form) return;

    // Fill the group and room pickers from the bucketed roster.
    await App.guard(async () => {
      const roster = (await API.get('resident.roster')) || {};
      const groups = roster.duty_groups || [];
      const rooms  = roster.rooms || [];

      $('#a-group').innerHTML = groups
        .map((g) => `<option value="${g.id}">${esc(g.name)} (${g.members})</option>`).join('');
      $('#a-room').innerHTML = rooms
        .map((r) => `<option value="${r.id}">${esc(r.code)} — ${esc(r.name)} (${r.occupied}/${r.capacity})</option>`).join('');
    });

    form.addEventListener('change', (ev) => {
      if (ev.target.name !== 'scope') return;
      $$('[data-when]', form).forEach((b) => b.classList.toggle('d-none', b.dataset.when !== ev.target.value));
    });

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      App.clearErrors(form);
      const payload = Object.fromEntries(new FormData(form).entries());
      const submit = form.querySelector('[type="submit"]');
      submit.disabled = true;

      await App.guard(async () => {
        const res = await API.post('chore.create_area', payload);
        App.toast(`"${res.name}" created — ${res.generated?.created ?? 0} task(s) scheduled.`, 'ok', 5000);
        form.reset();
        App.closeModal($('#newArea'));
        await loadAreas();
        await loadBoard();
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
    // Range inputs
    const fromInput = $('[data-range-from]');
    from = shiftIso(isoToday(), -3);
    to   = shiftIso(isoToday(), 10);
    if (fromInput) fromInput.value = from;

    fromInput?.addEventListener('change', () => {
      from = fromInput.value;
      // Keep the window at 14 days whatever the user picks.
      to = shiftIso(from, 13);
      loadBoard();
    });

    $('[data-today]')?.addEventListener('click', () => {
      from = shiftIso(isoToday(), -3);
      to   = shiftIso(isoToday(), 10);
      if (fromInput) fromInput.value = from;
      loadBoard();
    });

    $('[data-mine-days]')?.addEventListener('change', loadMine);

    // Admin: regenerate the board
    on(document, 'click', '[data-generate]', async () => {
      await App.guard(async () => {
        const res = await API.post('chore.generate', {});
        App.toast(`${res.created} task(s) created, ${res.skipped} already existed.`, 'ok', 5000);
        await loadBoard();
        await loadAreas();
      });
    });

    // Complete a chore
    on(document, 'click', '[data-complete]', async (ev, el) => {
      el.disabled = true;
      await App.guard(async () => {
        await API.choreComplete(Number(el.dataset.complete));
        App.toast('Chore completed.', 'ok');
        await Promise.all([loadBoard(), loadMine(), loadVerify()]);
      });
      el.disabled = false;
    });

    // Verify a chore (admin)
    on(document, 'click', '[data-verify]', async (ev, el) => {
      el.disabled = true;
      await App.guard(async () => {
        await API.choreVerify(Number(el.dataset.verify));
        App.toast('Verified — points locked in.', 'ok');
        await Promise.all([loadBoard(), loadMine(), loadVerify()]);
      });
      el.disabled = false;
    });

    // Skip with a reason
    on(document, 'click', '[data-skip]', async (ev, el) => {
      const id = Number(el.dataset.skip);
      const reason = await App.promptModal({
        title: 'Skip this chore?',
        label: 'Why is it being skipped?',
        placeholder: 'e.g. Water leak, plumber is coming Thursday',
        confirmText: 'Skip it',
      });
      if (!reason) return;

      await App.guard(async () => {
        await API.choreSkip(id, reason);
        App.toast('Skipped — the duty moved to the next person in the pool.', 'ok', 5000);
        await Promise.all([loadBoard(), loadMine()]);
      });
    });

    // Shift a rotation
    on(document, 'click', '[data-rotate]', async (ev, el) => {
      await App.guard(async () => {
        await API.post('chore.rotate', { area_id: Number(el.dataset.rotate), steps: 1 });
        App.toast('Rotation shifted by one.', 'ok');
        await loadAreas();
        await loadBoard();
      });
    });

    // Retire an area
    on(document, 'click', '[data-retire]', async (ev, el) => {
      await App.guard(async () => {
        await API.post('chore.delete_area', { id: Number(el.dataset.retire) });
        App.toast('Chore area retired.', 'ok');
        await loadAreas();
        await loadBoard();
      });
    });

    // Lazily load a tab the first time it is opened.
    $$('[data-bs-toggle="pill"]').forEach((tab) => {
      tab.addEventListener('shown.bs.tab', () => {
        switch (tab.dataset.bsTarget) {
          case '#tab-mine':   loadMine(); break;
          case '#tab-verify': loadVerify(); break;
          case '#tab-areas':  loadAreas(); break;
          default: break;
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', async () => {
    me = await App.guard(() => API.me());
    if (!me) return;
    wire();
    await loadBoard();
    await initAreaForm();
    // Warm the admin tabs straight away if we are on the page as an admin.
    if (me.role === 'admin') {
      loadVerify();
      loadAreas();
    }
  });
})();