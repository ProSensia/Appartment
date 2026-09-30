/* ==========================================================================
   FlatMate  |  Meal planner
   --------------------------------------------------------------------------
   Renders the 7x3 grid from `meal.week`, and drives opt-in, voting,
   suggestions and the grocery list.

   Note the domain rule this page makes visible: opting out of a meal never
   affects chore duty. Chores run on their own strict rotation.
   ========================================================================== */

(() => {
  'use strict';

  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const MEAL_ICON = { breakfast: 'bi-sunrise', lunch: 'bi-sun', dinner: 'bi-moon-stars' };
  const DAY_ORDER = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

  let me = null;
  let week = null;
  let anchor = null;          // any ISO date inside the week being shown
  let suggestMealId = null;

  /* ------------------------------------------------------------------ */
  /*  Render                                                             */
  /* ------------------------------------------------------------------ */

  function statusPill(slot) {
    const map = {
      eating:      ['ok',   'Eating'],
      maybe:       ['warn', 'Maybe'],
      opting_out:  ['muted','Opting out'],
    };
    if (!slot.my_status) {
      const miss = slot.coverage?.missing ?? 0;
      return `<span class="fm-pill bad">Needs your answer</span>`;
    }
    const [cls, label] = map[slot.my_status];
    return `<span class="fm-pill ${cls}">${label}</span>`;
  }

  function suggestionHtml(slot) {
    if (!slot.suggestions?.length) {
      return `<button class="btn btn-sm btn-link p-0" data-suggest="${slot.meal_id}">
                <i class="bi bi-plus-circle"></i> Suggest a dish
              </button>`;
    }

    const cards = slot.suggestions.map((s) => `
      <div class="fm-suggestion ${s.is_winner ? 'winner' : ''}" data-suggestion="${s.id}">
        <div class="d-flex align-items-start gap-2">
          <div class="flex-grow-1" style="min-width:0">
            <h4>${esc(s.title)}
              ${s.is_winner ? '<span class="fm-pill ok">Chosen</span>' : ''}</h4>
            <div class="text-faint" style="font-size:.75rem">
              by ${esc(s.full_name)}
              ${s.estimated_cost ? ' &middot; ' + esc(Fmt.money(Math.round(Number(s.estimated_cost) * 100))) : ''}
            </div>
            ${s.notes ? `<div class="text-muted-2 mt-1" style="font-size:.8rem">${esc(s.notes)}</div>` : ''}
          </div>
          <div class="fm-votes">
            <button class="btn btn-sm btn-link p-0" data-vote="${s.id}" data-v="1"
                    title="Up-vote">
              <i class="bi bi-caret-up-fill ${s.my_vote === 1 ? 'on' : ''}"></i>
              <span class="text-faint" style="font-size:.75rem">${s.ups ?? 0}</span>
            </button>
            <button class="btn btn-sm btn-link p-0" data-vote="${s.id}" data-v="-1"
                    title="Down-vote">
              <i class="bi bi-caret-down-fill ${s.my_vote === -1 ? 'on' : ''}"></i>
              <span class="text-faint" style="font-size:.75rem">${s.downs ?? 0}</span>
            </button>
          </div>
        </div>
      </div>`).join('');

    return cards + `<button class="btn btn-sm btn-link p-0" data-suggest="${slot.meal_id}">
        <i class="bi bi-plus"></i> Another option</button>`;
  }

  function slotHtml(slot) {
    // The server already resolved is_today/is_past against its own clock.
    const isToday = !!slot.is_today;
    const cov = slot.coverage || {};

    return `
      <div class="fm-meal-row ${isToday ? 'is-today' : ''}" data-slot="${slot.meal_id}">
        <div class="fm-meal-day ${isToday ? 'today' : ''}">
          ${esc(slot.day_short)}
          <small>${esc(Fmt.date(slot.date))}</small>
        </div>

        <div style="min-width:0">
          <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <i class="bi ${MEAL_ICON[slot.meal_type] || 'bi-cup-hot'} text-secondary"></i>
            <span class="fw-semibold text-capitalize">${esc(slot.meal_type)}</span>
            ${slot.cook
              ? `<span class="fm-pill muted"><i class="bi bi-fire"></i> ${esc(slot.cook.name)}</span>`
              : '<span class="fm-pill muted">no cook</span>'}
            ${statusPill(slot)}
            ${slot.locked ? '<span class="fm-pill warn">locked</span>' : ''}
          </div>

          ${slot.menu_title
            ? `<div class="fw-semibold" style="font-size:.9rem">${esc(slot.menu_title)}</div>`
            : '<div class="text-faint" style="font-size:.84rem">Nothing planned</div>'}

          <div class="text-faint mt-1" style="font-size:.74rem">
            ${cov.eaters ?? 0} eating &middot;
            ${cov.opting_out ?? 0} opting out
            ${cov.missing ? ` &middot; <span class="text-danger">${cov.missing} not answered</span>` : ''}
          </div>

          <div class="mt-1" data-suggestions>${suggestionHtml(slot)}</div>
        </div>

        <div class="fm-meal-end d-flex flex-column gap-1">
          <div class="fm-optin">
            <button data-respond="${slot.meal_id}" data-status="eating"
                    class="${slot.my_status === 'eating' ? 'on' : ''}">Eat</button>
            <button data-respond="${slot.meal_id}" data-status="maybe"
                    class="${slot.my_status === 'maybe' ? 'on' : ''}">Maybe</button>
            <button data-respond="${slot.meal_id}" data-status="opting_out"
                    class="${slot.my_status === 'opting_out' ? 'on out' : ''}">Out</button>
          </div>
        </div>
      </div>`;
  }

function render() {
    // meal.week() -> { plan, grid, stats }
    const plan = week?.plan;
    const grid = week?.grid || [];

    if (!plan || !grid.length) {
      $('[data-planner]').innerHTML = `
        <div class="fm-empty">
          <i class="bi bi-calendar-x"></i>
          <h3>No plan for this week</h3>
          <p>There is no published meal plan. An admin can create one from the Residents page.</p>
        </div>`;
      $('[data-week-label]').textContent = 'No plan';
      return;
    }

    $('[data-week-label]').textContent =
      `${Fmt.date(plan.week_start, { day: 'numeric', month: 'short' })} \u2013 ${Fmt.date(plan.week_end, { day: 'numeric', month: 'short', year: 'numeric' })}`
      + `  \u00b7  ${week.stats?.total_eater_slots ?? 0} covers this week`;

    const byDay = new Map();
    grid.forEach((slot) => {
      if (!byDay.has(slot.day_of_week)) byDay.set(slot.day_of_week, []);
      byDay.get(slot.day_of_week).push(slot);
    });

    const html = DAY_ORDER.map((day, idx) => {
      const slots = byDay.get(idx + 1) || [];
      const header = slots[0];
      return `
        <div class="fm-section-title d-flex align-items-center gap-2 px-2 mt-2">
          ${esc(header?.day_name || day)}
          ${header ? `<span class="text-faint fw-normal" style="text-transform:none;letter-spacing:0">${esc(Fmt.date(header.date))}</span>` : ''}
        </div>
        ${slots.length
          ? slots.map(slotHtml).join('')
          : `<div class="fm-meal-row"><div class="fm-meal-day">&mdash;</div>
               <div class="text-faint" style="font-size:.85rem">No slots generated for this day.</div></div>`}`;
    }).join('');

    $('[data-planner]').innerHTML = html;
  }

  /* ------------------------------------------------------------------ */
  /*  Actions                                                            */
  /* ------------------------------------------------------------------ */

  async function load() {
    week = await API.get('meal.week', { date: anchor });
    // Keep the anchor inside the week that was actually returned, so paging
    // forward from an out-of-range date stays consistent.
    if (week?.plan?.week_start) anchor = week.plan.week_start;
    render();
  }

  function wire() {
    // Week navigation
    $$('[data-week-shift]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const shift = Number(btn.dataset.weekShift);
        if (shift === 0) {
          anchor = new Date().toISOString().slice(0, 10);
        } else {
          const d = new Date(`${anchor}T00:00:00Z`);
          d.setUTCDate(d.getUTCDate() + shift * 7);
          anchor = d.toISOString().slice(0, 10);
        }
        await App.guard(load);
      });
    });

    // Per-slot opt-in
    on(document, 'click', '[data-respond]', async (ev, el) => {
      ev.preventDefault();
      await App.guard(async () => {
        await API.mealRespond(Number(el.dataset.respond), el.dataset.status);
        await load();
      });
    });

    // Bulk opt-in for the whole week
    $$('[data-bulk]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        await App.guard(async () => {
          const res = await API.mealBulk(btn.dataset.bulk, anchor);
          App.toast(`Updated ${res?.updated ?? 0} slot(s).`, 'ok');
          await load();
        });
      });
    });

    // Votes
    on(document, 'click', '[data-vote]', async (ev, el) => {
      ev.preventDefault();
      await App.guard(async () => {
        await API.mealVote(Number(el.dataset.vote), Number(el.dataset.v));
        await load();
      });
    });

    // Open the suggest modal for a slot
    on(document, 'click', '[data-suggest]', (ev, el) => {
      ev.preventDefault();
      suggestMealId = Number(el.dataset.suggest);
      const slot = (week?.grid || []).find((s) => s.meal_id === suggestMealId);
      $('[data-suggest-when]').textContent = slot
        ? `${slot.day_name}, ${slot.meal_type} — ${Fmt.dateLong(slot.date)}`
        : '';
      App.openModal($('#suggest'));
    });

    // Submit a suggestion
    const form = $('[data-form="suggest"]');
    form?.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      App.clearErrors(form);
      const payload = Object.fromEntries(new FormData(form).entries());
      payload.meal_id = suggestMealId;

      const submit = form.querySelector('[type="submit"]');
      submit.disabled = true;
      await App.guard(async () => {
        await API.post('meal.suggest', payload);
        App.toast('Suggestion added — go vote!', 'ok');
        form.reset();
        App.closeModal($('#suggest'));
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

    // Grocery list
    $('[data-bs-target="#grocery"]')?.addEventListener('shown.bs.modal', async () => {
      $('[data-grocery-body]').innerHTML = '<div class="fm-skeleton" style="height:6rem"></div>';
      await App.guard(async () => {
        const g = await API.get('meal.grocery', { date: anchor });
        $('[data-grocery-body]').innerHTML = g.items?.length
          ? `<p class="fm-section-title">${g.items.length} distinct dish(es), busiest first</p>
             ${g.items.map((it) => `
               <div class="d-flex align-items-center gap-2 py-1" style="border-bottom:1px dashed var(--fm-border)">
                 <i class="bi bi-check-square text-secondary"></i>
                 <div class="flex-grow-1">
                   <div style="font-size:.9rem;font-weight:600">${esc(it.dish)}</div>
                   <div class="text-faint" style="font-size:.74rem">
                     ${it.slots?.map((s) => `${esc(s.day)} ${esc(s.type)}`).join(', ') || ''}
                   </div>
                 </div>
                 <span class="fm-pill ${it.head_count > 4 ? 'warn' : 'muted'}">
                   ${it.head_count} eating
                 </span>
               </div>`).join('')}
             ${g.unplanned
               ? `<p class="text-faint mt-2 mb-0" style="font-size:.78rem">
                    ${g.unplanned} slot(s) still have no menu.</p>` : ''}`
          : '<p class="text-muted-2 mb-0">No dishes planned for this week yet.</p>';
      });
    });

    $('[data-copy]')?.addEventListener('click', async () => {
      const text = $$('[data-grocery-body] .flex-grow-1').map((el) => el.children[0].textContent.trim()).join('\n');
      if (!text) return;
      await navigator.clipboard.writeText(text).then(
        () => App.toast('Grocery list copied.', 'ok'),
        () => App.toast('Clipboard is blocked by the browser.', 'warn')
      );
    });
  }

  document.addEventListener('DOMContentLoaded', async () => {
    me = await App.guard(() => API.me());
    if (!me) return;
    anchor = new Date().toISOString().slice(0, 10);
    wire();
    await App.guard(load);
  });
})();