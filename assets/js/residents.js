/* ==========================================================================
   FlatMate  |  Residents
   --------------------------------------------------------------------------
   Roster, invitations, rooms and duty groups.

   Note the room/group relationship: assertGroupMembership() requires a duty
   group to be a subset of its room, so the two pickers in the invite form are
   not independent — the server rejects an inconsistent pair and the error is
   mapped back onto the room field.
   ========================================================================== */

(() => {
  'use strict';

  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  let me       = null;
  let residents = [];
  let invites   = [];
  let rooms     = [];
  let groups    = [];
  let detailFor = null;

  const isAdmin = () => me?.role === 'admin';

  /* ------------------------------------------------------------------ */
  /*  Roster                                                             */
  /* ------------------------------------------------------------------ */

  const STATUS_BADGE = {
    active:     ['ok', 'Active'],
    invited:    ['warn', 'Invited'],
    suspended:  ['bad', 'Suspended'],
    offboarded: ['muted', 'Offboarded'],
  };

  function residentRow(u) {
    const [cls, label] = STATUS_BADGE[u.status] || ['muted', u.status];
    const off = u.offboarding || {};
    const meFlag = u.id === me.id;

    const offBlock = off.active && off.total > 0
      ? `<div class="mt-1">
           <div class="d-flex align-items-center gap-2">
             <div class="fm-fair-track flex-grow-1">
               <div class="fm-fair-fill ${off.blocking ? 'over' : ''}" style="width:${off.percent}%"></div>
             </div>
             <span class="text-faint" style="font-size:.7rem">${off.done}/${off.total} done</span>
           </div>
           ${off.blocking ? `<div class="text-danger" style="font-size:.72rem">
             ${off.blocking} blocking item${off.blocking === 1 ? '' : 's'} left</div>` : ''}
         </div>`
      : '';

    return `
      <div class="fm-resident" data-resident="${u.id}">
        <span class="fm-avatar" style="background:${esc(u.avatar_color || '#64748b')}">
          ${esc(u.initials || Fmt.initials(u.full_name))}
        </span>

        <div class="fm-resident-main">
          <div class="fm-resident-name">
            ${esc(u.full_name)}${meFlag ? ' <span class="fm-pill muted">you</span>' : ''}
            <span class="fm-pill ${cls}">${esc(label)}</span>
            ${u.net_cents !== 0
              ? `<span class="fm-pill ${u.direction === 'credit' ? 'ok' : 'bad'}">
                   ${esc(u.direction === 'credit' ? 'is owed' : 'owes')}
                   ${esc(u.net_label)}
                 </span>`
              : ''}
          </div>
          <div class="fm-resident-meta">
            ${u.participant_code ? esc(u.participant_code) : 'no code'}
            ${u.room_code ? ` &middot; ${esc(u.room_code)}` : ''}
            ${u.duty_group_name ? ` &middot; ${esc(u.duty_group_name)}` : ''}
            &middot; ${u.chores_pending} chore${u.chores_pending === 1 ? '' : 's'} pending
          </div>
          ${offBlock}
        </div>

        <button class="btn btn-sm btn-outline-secondary" data-open="${u.id}">
          <i class="bi bi-chevron-right"></i>
        </button>
      </div>`;
  }

  async function loadRoster() {
    residents = await App.guard(() => API.get('resident.list')) || [];

    const q = ($('[data-roster-search]')?.value || '').toLowerCase();
    const status = $('[data-roster-status]')?.value || '';

    const list = residents.filter((u) => {
      if (status && u.status !== status) return false;
      if (!q) return true;
      return (u.full_name || '').toLowerCase().includes(q)
          || (u.participant_code || '').toLowerCase().includes(q)
          || (u.room_code || '').toLowerCase().includes(q);
    });

    $('[data-roster]').innerHTML = list.length
      ? `<div class="fm-card">${list.map(residentRow).join('')}</div>`
      : `<div class="fm-card"><div class="fm-empty">
           <i class="bi bi-person-x"></i><h3>Nobody matches</h3>
           <p>Try a different search or status filter.</p>
         </div></div>`;
  }

  async function openResident(id) {
    const u = await App.guard(() => API.get('resident.find', { id }));
    if (!u || !u.id) return;

    // The checklist lives in its own endpoint; the summary in `all()` only
    // carries counts.
    const offboard = await App.guard(() => API.get('resident.offboard_view', { user_id: id })) || {};
    const off = {
      ...(u.offboarding || {}),
      items: offboard.items || [],
      balance_label: offboard.balance_label || u.net_label,
      balance_settled: !!offboard.balance_settled,
      blocking_labels: offboard.blocking_labels || [],
    };
    detailFor = { ...u, offboarding: off };

    $('[data-detail-title]').textContent = u.full_name;

    const [cls, label] = STATUS_BADGE[u.status] || ['muted', u.status];

    $('[data-detail-body]').innerHTML = `
      <div class="d-flex align-items-center gap-3 mb-3">
        <span class="fm-avatar lg" style="background:${esc(u.avatar_color || '#64748b')}">
          ${esc(u.initials || Fmt.initials(u.full_name))}
        </span>
        <div class="flex-grow-1">
          <div class="d-flex align-items-center gap-2">
            <span class="fm-resident-name">${esc(u.full_name)}</span>
            <span class="fm-pill ${cls}">${esc(label)}</span>
          </div>
          <div class="text-faint" style="font-size:.78rem">
            ${esc(u.email)}
            ${u.participant_code ? ` &middot; ${esc(u.participant_code)}` : ''}
          </div>
        </div>
      </div>

      <div class="row g-2 mb-3">
        <div class="col-4"><div class="fm-stat">
          <div class="fm-stat-label">Net</div>
          <div class="fm-stat-value ${u.direction === 'credit' ? 'owing' : (u.direction === 'debit' ? 'owed' : 'flat')}"
               style="font-size:1rem">${esc(u.net_label)}</div>
        </div></div>
        <div class="col-4"><div class="fm-stat">
          <div class="fm-stat-label">Paid</div>
          <div class="fm-stat-value" style="font-size:1rem">${esc(Fmt.money(u.total_paid_cents ?? 0))}</div>
        </div></div>
        <div class="col-4"><div class="fm-stat">
          <div class="fm-stat-label">Chores done</div>
          <div class="fm-stat-value" style="font-size:1rem">
            ${u.chores_total - u.chores_pending}
            <span class="text-faint" style="font-size:.7rem">of ${u.chores_total}</span>
          </div>
        </div></div>
      </div>

      <div class="row g-2 mb-3">
        <div class="col-6">
          <label class="form-label" for="d-room">Room</label>
          <select class="form-select form-select-sm" id="d-room" name="room_id" data-detail-room>
            ${rooms.map((r) => `<option value="${r.id}" ${r.id === u.room_id ? 'selected' : ''}>
              ${esc(r.code)} — ${esc(r.name)} (${r.occupied}/${r.capacity})
            </option>`).join('')}
          </select>
        </div>
        <div class="col-6">
          <label class="form-label" for="d-group">Duty group</label>
          <select class="form-select form-select-sm" id="d-group" name="duty_group_id" data-detail-group>
            ${groups.map((g) => `<option value="${g.id}" ${g.id === u.duty_group_id ? 'selected' : ''}>
              ${esc(g.name)} (${g.members})
            </option>`).join('')}
          </select>
        </div>
      </div>

      ${off.total ? `
        <p class="fm-section-title">Offboarding checklist</p>
        <div class="mb-3">
          <div class="fm-fair-track mb-1">
            <div class="fm-fair-fill ${off.blocking ? 'over' : ''}" style="width:${off.percent}%"></div>
          </div>
          <p class="text-faint mb-2" style="font-size:.76rem">
            ${off.done} of ${off.total} done${off.blocking ? ` — ${off.blocking} blocking item(s) remain` : ''}.
          </p>
          ${(off.items || []).map((t) => `
            <div class="fm-resident" style="padding:.45rem .25rem">
              <button class="btn btn-sm ${t.is_done ? 'btn-success' : 'btn-outline-secondary'}"
                      data-toggle-task="${t.id}" data-done="${t.is_done ? '1' : '0'}"
                      title="${t.is_done ? 'Mark as not done' : 'Mark as done'}">
                <i class="bi bi-check-lg"></i>
              </button>
              <div class="fm-resident-main">
                <div class="fm-resident-name" style="font-size:.85rem">
                  <i class="bi ${esc(t.icon || 'bi-clipboard-check')}"></i> ${esc(t.label)}
                </div>
                <div class="fm-resident-meta">
                  ${esc(t.category_label || '')}
                  ${t.is_blocking ? ' &middot; <span class="text-danger">blocking</span>' : ''}
                </div>
              </div>
            </div>`).join('')}
        </div>` : ''}

      ${isAdmin() ? `
        <div class="d-flex flex-wrap gap-2">
          ${u.status === 'active'
            ? `<button class="btn btn-sm btn-outline-warning" data-status="suspended" data-id="${u.id}">
                 Suspend
               </button>`
            : ''}
          ${u.status === 'suspended'
            ? `<button class="btn btn-sm btn-outline-success" data-status="active" data-id="${u.id}">
                 Reinstate
               </button>`
            : ''}
          ${u.status !== 'offboarded'
            ? `<button class="btn btn-sm btn-outline-danger"
                       data-offboard="${u.id}" data-name="${esc(u.full_name)}"
                       data-confirm-title="Start offboarding?"
                       data-confirm-text="A checklist appears for clearing dues, returns and final chores. Their history stays visible."
                       data-confirm-ok="Start">
                 Start offboarding
               </button>`
            : `<button class="btn btn-sm btn-outline-success" data-reinstate="${u.id}">
                 Reinstate
               </button>`}

          ${off.total && u.status !== 'offboarded' ? `
            <button class="btn btn-sm btn-danger" data-offboard-complete="${u.id}"
                    data-name="${esc(u.full_name)}"
                    data-confirm-title="Complete offboarding?"
                    data-confirm-text="${esc(u.full_name)} is marked as offboarded and loses access to the app. Their records are kept for the ledger history."
                    data-confirm-ok="Complete offboarding"
                    data-confirm-danger="1"
                    ${off.blocking ? 'disabled title="Blocking items must clear first"' : ''}>
              <i class="bi bi-check2-circle"></i> Complete offboarding
            </button>` : ''}
        </div>`
      : `<p class="text-faint mb-0" style="font-size:.78rem">
           Only an admin can change a resident's room, group or status.
         </p>`}`;

    App.openModal($('#residentDetail'));
  }

  /* ------------------------------------------------------------------ */
  /*  Invites                                                            */
  /* ------------------------------------------------------------------ */

  async function loadInvites() {
    const host = $('[data-invites]');
    if (!host) return;

    const list = await App.guard(() => API.get('resident.invites')) || [];
    invites = list;
    const pending = list.filter((i) => i.status === 'pending');

    const badge = $('[data-pending-invites]');
    if (badge) {
      badge.textContent = pending.length;
      badge.classList.toggle('d-none', pending.length === 0);
    }

    host.innerHTML = list.length
      ? `<div>${list.map((i) => `
          <div class="fm-resident ${i.status === 'pending' ? 'invited' : ''}">
            <span class="fm-avatar sm"><i class="bi bi-envelope"></i></span>
            <div class="fm-resident-main">
              <div class="fm-resident-name">
                ${esc(i.email)}
                <span class="fm-pill ${i.status === 'pending' ? 'warn' : (i.status === 'accepted' ? 'ok' : 'muted')}">
                  ${esc(i.status)}
                </span>
              </div>
              <div class="fm-resident-meta">
                invited by ${esc(i.inviter_name || 'an admin')}
                ${i.room_code ? ` &middot; ${esc(i.room_code)}` : ''}
                ${i.duty_group_name ? ` &middot; ${esc(i.duty_group_name)}` : ''}
                ${i.expires_at ? ` &middot; expires ${esc(Fmt.date(String(i.expires_at).slice(0, 10)))}` : ''}
              </div>
            </div>
            ${i.status === 'pending' ? `
              <button class="btn btn-sm btn-outline-secondary" data-reissue="${i.id}">Re-issue link</button>
              <button class="btn btn-sm btn-outline-danger" data-revoke="${i.id}"
                      data-confirm-title="Revoke this invite?"
                      data-confirm-text="${esc(i.email)} will no longer be able to join with this link."
                      data-confirm-ok="Revoke" data-confirm-danger="1">Revoke</button>`
            : ''}
          </div>`).join('')}</div>`
      : '<p class="text-muted-2 mb-0 p-3" style="font-size:.85rem">No invitations yet.</p>';
  }

  function showInviteLink(invite) {
    // The raw token is returned once, at creation. Later calls re-issue a
    // fresh link so an admin can always recover access to it.
    const link = invite.join_url || invite.accept_path || '';
    $('[data-invite-link]').value = link;
    App.openModal($('#inviteLink'));
  }

  async function initInviteForm() {
    const form = $('[data-form="invite"]');
    if (!form) return;

    $('[data-rooms-select]', form).innerHTML = '<option value="">No room yet</option>'
      + rooms.map((r) => `<option value="${r.id}">${esc(r.code)} — ${esc(r.name)} (${r.occupied}/${r.capacity})</option>`).join('');
    $('[data-groups-select]', form).innerHTML = '<option value="">No group</option>'
      + groups.map((g) => `<option value="${g.id}">${esc(g.name)}</option>`).join('');

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      App.clearErrors(form);
      const payload = Object.fromEntries(new FormData(form).entries());

      const submit = form.querySelector('[type="submit"]');
      submit.disabled = true;
      await App.guard(async () => {
        const invite = await API.post('resident.invite', payload);
        App.toast('Invitation created.', 'ok');
        form.reset();
        App.closeModal($('#inviteModal'));
        showInviteLink(invite);
        await Promise.all([loadInvites(), loadRoster(), loadRooms()]);
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
  /*  Rooms + groups                                                     */
  /* ------------------------------------------------------------------ */

  async function loadRooms() {
    const bucket = await App.guard(() => API.get('resident.roster')) || {};
    rooms = bucket.rooms || [];
    groups = bucket.duty_groups || [];

    $('[data-rooms]').innerHTML = rooms.length
      ? `<div>${rooms.map((r) => {
          const full = r.occupied >= r.capacity;
          return `
            <div class="fm-resident">
              <span class="fm-avatar sm"><i class="bi bi-door-open"></i></span>
              <div class="fm-resident-main">
                <div class="fm-resident-name">
                  ${esc(r.code)} <span class="text-faint fw-normal">${esc(r.name || '')}</span>
                </div>
                <div class="fm-resident-meta">
                  ${r.floor ? esc(r.floor) + ' &middot; ' : ''}sleeps ${r.capacity}
                </div>
              </div>
              <span class="fm-pill ${full ? 'bad' : 'ok'}">${r.occupied}/${r.capacity}</span>
            </div>`;
        }).join('')}</div>`
      : '<p class="text-muted-2 mb-0 p-3" style="font-size:.85rem">No rooms configured.</p>';

    $('[data-groups]').innerHTML = groups.length
      ? `<div>${groups.map((g) => `
          <div class="fm-resident">
            <span class="fm-avatar sm" style="background:${esc(g.color || '#64748b')}">
              <i class="bi bi-people"></i>
            </span>
            <div class="fm-resident-main">
              <div class="fm-resident-name">${esc(g.name)}</div>
              <div class="fm-resident-meta">${esc(g.description || 'No description')}</div>
            </div>
            <span class="fm-pill muted">${g.members} member${g.members === 1 ? '' : 's'}</span>
          </div>`).join('')}</div>`
      : '<p class="text-muted-2 mb-0 p-3" style="font-size:.85rem">No duty groups configured.</p>';
  }

  /* ------------------------------------------------------------------ */
  /*  Wiring                                                             */
  /* ------------------------------------------------------------------ */

  function wire() {
    let timer = null;
    $('[data-roster-search]')?.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(loadRoster, 250);
    });
    $('[data-roster-status]')?.addEventListener('change', loadRoster);

    // Open a resident
    on(document, 'click', '[data-resident]', (ev, el) => {
      if (ev.target.closest('button[data-offboard]')) return;
      openResident(Number(el.dataset.resident));
    });

    // Copy the invite link
    on(document, 'click', '[data-copy-invite]', async () => {
      const input = $('[data-invite-link]');
      input.select();
      await navigator.clipboard.writeText(input.value).then(
        () => App.toast('Invitation link copied.', 'ok'),
        () => App.toast('Copy failed — select and copy manually.', 'warn')
      );
    });

    // Revoke an invite
    on(document, 'click', '[data-revoke]', async (ev, el) => {
      await App.guard(async () => {
        await API.post('resident.revoke', { id: Number(el.dataset.revoke) });
        App.toast('Invitation revoked.', 'ok');
        await loadInvites();
      });
    });

    // Re-issue an invite link. invite() is keyed on the email address, so the
    // old link is revoked and a fresh token minted for the same address.
    on(document, 'click', '[data-reissue]', async (ev, el) => {
      const id = Number(el.dataset.reissue);
      const invite = invites.find((i) => i.id === id);
      if (!invite) return;

      await App.guard(async () => {
        await API.post('resident.revoke', { id });
        const fresh = await API.post('resident.invite', {
          email: invite.email,
          room_id: invite.room_id ?? null,
          duty_group_id: invite.duty_group_id ?? null,
        });
        showInviteLink(fresh);
        await loadInvites();
      });
    });

    // Offboarding checklist toggles
    on(document, 'click', '[data-toggle-task]', async (ev, el) => {
      el.disabled = true;
      await App.guard(async () => {
        await API.post('resident.checklist_toggle', {
          id: Number(el.dataset.toggleTask),
          done: el.dataset.done !== '1',
        });
        await Promise.all([loadRoster(), loadRooms()]);
        await openResident(detailFor.id);
      });
      el.disabled = false;
    });

    // Change room / group from the detail modal
    on(document, 'change', '[data-detail-room]', async (ev) => {
      await saveDetail({ room_id: Number(ev.target.value) || null });
    });
    on(document, 'change', '[data-detail-group]', async (ev) => {
      await saveDetail({ duty_group_id: Number(ev.target.value) || null });
    });

    // Suspend / reinstate
    on(document, 'click', '[data-status]', async (ev, el) => {
      const status = el.dataset.status;
      await App.guard(async () => {
        await API.post('resident.update', { id: Number(el.dataset.id), status });
        App.toast(status === 'suspended' ? 'Resident suspended.' : 'Resident reinstated.', 'ok');
        await Promise.all([loadRoster(), loadInvites(), loadRooms()]);
        await openResident(Number(el.dataset.id));
      }, {
        onError: (err) => {
          if (err instanceof API.ApiError && err.code === 'validation_failed') {
            App.toast(Object.values(err.details)[0] || err.message, 'err');
          }
        },
      });
    });

    // Offboarding
    on(document, 'click', '[data-offboard]', async (ev, el) => {
      const id = Number(el.dataset.offboard);
      await App.guard(async () => {
        // beginOffboarding() returns the full offboarding view.
        const plan = await API.post('resident.offboard_start', { user_id: id });
        App.toast('Offboarding checklist created.', 'ok');
        await Promise.all([loadRoster(), loadRooms()]);
        await openResident(id);
        const blocking = plan?.blocking_open ?? 0;
        if (blocking) {
          App.toast(`${blocking} blocking item(s) must clear before offboarding completes.`, 'warn', 6500);
        }
      });
    });

    on(document, 'click', '[data-reinstate]', async (ev, el) => {
      await App.guard(async () => {
        const id = Number(el.dataset.reinstate);
        await API.post('resident.reinstate', { user_id: id });
        App.toast('Resident reinstated.', 'ok');
        await Promise.all([loadRoster(), loadRooms()]);
        await openResident(id);
      });
    });

    // Finish offboarding. The server re-checks the blocking items, so a stale
    // button cannot offboard someone who still owes money.
    on(document, 'click', '[data-offboard-complete]', async (ev, el) => {
      const id = Number(el.dataset.offboardComplete);
      el.disabled = true;
      await App.guard(async () => {
        await API.post('resident.offboard', { user_id: id });
        App.toast(`${el.dataset.name} is now offboarded.`, 'ok');
        App.closeModal($('#residentDetail'));
        await Promise.all([loadRoster(), loadRooms()]);
      });
      el.disabled = false;
    });

    // Lazily load tabs.
    $$('[data-bs-toggle="pill"]').forEach((tab) => {
      tab.addEventListener('shown.bs.tab', () => {
        if (tab.dataset.bsTarget === '#tab-invites') loadInvites();
      });
    });
  }

  async function saveDetail(patch) {
    if (!detailFor) return;
    await App.guard(async () => {
      await API.post('resident.update', { id: detailFor.id, ...patch });
      App.toast('Updated.', 'ok');
      await Promise.all([loadRoster(), loadRooms()]);
      await openResident(detailFor.id);
    }, {
      onError: (err) => {
        if (err instanceof API.ApiError && err.code === 'validation_failed') {
          App.toast(Object.values(err.details)[0] || err.message, 'err');
          // Reopen from the server so the rejected value does not linger.
          openResident(detailFor.id);
        }
      },
    });
  }

  document.addEventListener('DOMContentLoaded', async () => {
    me = await App.guard(() => API.me());
    if (!me) return;
    wire();
    await loadRooms();
    await loadRoster();
    if (isAdmin()) await initInviteForm();
  });
})();