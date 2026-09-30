# API

One endpoint, `api/index.php`, dispatched on an `action` parameter. There is no
version prefix and no second entry point.

```
GET|POST /api/index.php?action=<action>
```

## Conventions

**Envelope.** Success is `{"ok": true, "data": ...}`. Failure is
`{"ok": false, "error": {"message": ..., "code": ..., "details": {...}}}`.

**Status codes.** `422` validation (with per-field `details`), `400` bad request,
`401` unauthenticated, `403` authenticated but not permitted, `404` unknown
action, `500` anything unexpected.

**Auth.** Two schemes, resolved by `src/ApiAuth.php`:

- A session cookie. Same-origin, which is what the web UI uses.
- `Authorization: Bearer <token>` from `ApiAuth::issueToken()`. Bearer requests
  skip the CSRF check, since the credential isn't ambient to a browser.

Both point at the same `sessions` table as "remember me", and both require
`status = 'active'`. Suspended and offboarded residents are rejected before any
handler runs.

**CSRF.** Cookie-authenticated `POST`s must send the token from `auth.csrf`,
either as an `X-CSRF-Token` header or a `csrf_token` field.

**Money.** Every amount in a response is an integer in cents under a `*_cents`
key. (MySQL itself stores `DECIMAL(12,2)`; the conversion happens in PHP.) No
endpoint accepts or returns a decimal string as a monetary amount — including
`auth.login`, whose `rent_amount` input is a plain major-unit number such as
`12000.00` and is parsed into `DECIMAL` on the way in.

**Self-test.** `?verify=1` on any non-public read wraps the response as
`{"result": ..., "verification": SelfTest::run()}`. Convenient for checking the
pure algorithms after a deploy; too slow to leave on.

**Guard levels** in the table below: `public` (no session needed), `auth`
(any active resident), `admin` (`role = 'admin'`).

---

## Auth

| Action | Method | Guard | Inputs |
|---|---|---|---|
| `auth.login` | POST | public | `email`, `password`, `remember?` |
| `auth.logout` | POST | auth | — |
| `auth.me` | GET | auth | — |
| `auth.csrf` | GET | auth | → `{token}` |
| `auth.magic_request` | POST | public | `email` |
| `auth.magic_redeem` | POST | public | `token` |

`auth.me` returns the full user row: `id`, `full_name`, `email`, `role`, `status`,
`initials`, `avatar_color`, `participant_code`, `room_id`, `duty_group_id`.

---

## Dashboard

| Action | Method | Guard | Inputs |
|---|---|---|---|
| `dashboard` | GET | auth | — |
| `activity` | GET | auth | `limit?`, `offset?` |

`dashboard` is a composed payload, not one query:

```jsonc
{
  "user":       { "id": 1, "full_name": "...", "net_cents": -2500 },
  "money": {
    "summary":   { "total_spend_cents": 0, "you_owe_cents": 0, "you_are_owed_cents": 0 },
    "suggestion": [ { "from_user_id": 2, "to_user_id": 1, "amount_cents": 1500 } ],
    "recent":    [ /* expenses */ ]
  },
  "chores":     { "today": [], "upcoming": [], "overdue": [] },
  "meals":      { "plan": {}, "grid": {} },
  "notices":    { "unread": 2, "items": [] },
  "reminders":  [ /* reminder rows */ ]
}
```

`money.suggestion` comes from `DebtSimplifier` and is already reduced, so it can
be rendered directly as "pay these N transfers".

---

## Chores

| Action | Method | Guard | Inputs |
|---|---|---|---|
| `chore.board` | GET | auth | `from?`, `to?` |
| `chore.mine` | GET | auth | `days?` |
| `chore.areas` | GET | auth | — |
| `chore.area` | GET | auth | `id` |
| `chore.upcoming` | GET | auth | — |
| `chore.fairness` | GET | auth | `days?` |
| `chore.complete` | POST | auth | `id`, `done` |
| `chore.verify` | POST | admin | `id` |
| `chore.skip` | POST | auth | `id`, `reason?` |
| `chore.reassign` | POST | admin | `id`, `user_id?` |
| `chore.generate` | POST | admin | `from?`, `to?` |
| `chore.rotate` | POST | admin | — |
| `chore.create_area` | POST | admin | `name`, `icon`, `scope`, `frequency`, `room_id?`, `duty_group_id?`, `rotation_offset?`, `weekday_mask?` |
| `chore.update_area` | POST | admin | `id`, …same as create |
| `chore.delete_area` | POST | admin | `id` |

`chore.mine` is **grouped by urgency**, not flat:

```json
{ "today": [], "upcoming": [], "overdue": [], "count": 0 }
```

Board and individual tasks are decorated with `area_name`, `icon`, `is_mine` and
`is_unassigned`. `chore.complete` accepts `done: 0` to undo.

Duty assignment is computed, not stored per day — see
[ARCHITECTURE.md](ARCHITECTURE.md#chore-rotation).

---

## Meals

| Action | Method | Guard | Inputs |
|---|---|---|---|
| `meal.week` | GET | auth | `week_start?` |
| `meal.set_menu` | POST | auth | `date`, `meal`, `dish` |
| `meal.suggest` | POST | auth | `date`, `meal`, `dish` |
| `meal.vote` | POST | auth | `id`, `vote` |
| `meal.accept` | POST | auth | `id` |
| `meal.cook` | POST | auth | `id` |
| `meal.respond` | POST | auth | `meal_id`, `status` (`eating`\|`opting_out`), `user_id?` |
| `meal.bulk_respond` | POST | auth | `status`, `scope?` (`week`\|`day`), `date?` |
| `meal.grocery` | GET | auth | `week_start?` |
| `meal.set_status` | POST | admin | `plan_id`, `status` (`draft`\|`locked`\|`archived`) |

`meal.week` returns three parts:

```json
{ "plan": { "week_start": "2026-09-28", "week_end": "2026-10-04" },
  "grid": [ /* one row per day, each with per-meal slots */ ],
  "stats": { "planned_slots": 0, "total_slots": 0, "open_slots": 0 } }
```

`meal.status` (for `meal.respond`) is one of `eating` or `opting_out`.
`meal.set_status` operates on the weekly plan instead, where the valid values are
`draft`, `locked` and `archived`. Both are validated server-side with a
`validation_failed` response naming the field.

---

## Expenses and balances

| Action | Method | Guard | Inputs |
|---|---|---|---|
| `expense.list` | GET | auth | filters below |
| `expense.count` | GET | auth | same filters |
| `expense.find` | GET | auth | `id` |
| `expense.create` | POST | auth | `title`, `amount`, `paid_by_user_id`, `category_id`, `split_type`, `expense_date?`, `description?`, `reference_no?`, `splits?`, `is_meal_related?` |
| `expense.delete` | POST | auth | `id` |
| `expense.dispute` | POST | auth | `id`, `reason` |
| `expense.categories` | GET | auth | — |
| `expense.audit` | GET | admin | `id` |
| `balance.ledger` | GET | auth | `days?` |
| `balance.summary` | GET | auth | — |
| `balance.statement` | GET | auth | `user_id` |
| `balance.categories` | GET | auth | `from?`, `to?` |
| `balance.simplify` | GET | auth | `strategy?` (`auto`\|`greedy`\|`optimal`) |
| `balance.settle` | POST | auth | `from_user_id`, `to_user_id`, `amount`, `method?`, `reference?`, `note?`, `settled_at?` |
| `balance.settlements` | GET | auth | `limit?` |

### List filters

`q`, `category_id`, `split_type`, `from`, `to`, `meal_only`, `disputed`,
`limit`, `offset`, plus two resident-scoped ones:

- `user_id` — expenses the resident **paid or shared in**. The UI's "Shared with me".
- `payer_id` — expenses they **specifically paid**. The UI's "Paid by me".

These are separate on purpose. Filtering `payer_id` in the browser would narrow an
already-paged result set, so the page and the total would disagree; both go to SQL.

### Split types

| `split_type` | `splits` payload |
|---|---|
| `equal` | ignored — computed server-side across all active residents |
| `selective` | `[{user_id, share_cents}]` |
| `shares` | `[{user_id, weight}]` |
| `meal_based` | ignored — computed from opt-ins in the meal window |

There is no `custom` type. Amounts are validated to conserve the total to the cent.

### Balances

`balance.statement` returns the shape the dashboard and expenses page both read:

```json
{ "member":        { },
  "outgoing":      [ /* what they owe */ ],
  "incoming":      [ /* what they're owed */ ],
  "paid_expenses": [ ],
  "owed_expenses": [ ],
  "settlements":   [ ],
  "summary":       { "total_spend_cents": 0, "net_cents": 0 } }
```

Note `summary.total_spend_cents` is **all-time**, not this month, despite living
next to month-scoped numbers elsewhere.

`balance.simplify` always returns transfers in `amount_cents` under
`from_user_id`/`to_user_id`, whichever strategy runs.

---

## Residents

| Action | Method | Guard | Inputs |
|---|---|---|---|
| `resident.list` | GET | auth | `status?` |
| `resident.find` | GET | auth | `id` |
| `resident.roster` | GET | auth | — |
| `resident.update` | POST | admin | `id`, `full_name?`, `phone?`, `room_id?`, `duty_group_id?`, `status?` |
| `resident.invite` | POST | admin | `email`, `full_name?`, `room_id?`, `duty_group_id?` |
| `resident.invites` | GET | admin | — |
| `resident.revoke` | POST | admin | `id` |
| `resident.accept` | POST | public | `token`, `password`, `full_name?` |
| `resident.offboard` | POST | admin | `user_id`, `force?` |
| `resident.offboard_start` | POST | admin | `user_id` |
| `resident.offboard_view` | GET | auth | `user_id` |
| `resident.checklist_toggle` | POST | admin | `id`, `done` |
| `resident.reinstate` | POST | admin | `user_id` |

`resident.roster` is **bucketed** — three independent lists, because the UI needs
all three to render the filters:

```json
{ "rooms": [], "duty_groups": [], "residents": [] }
```

`resident.list` rows carry an `offboarding` **summary**
(`{total, done, blocking, percent, active}`). The full checklist with individual
tasks lives only on `resident.offboard_view`, since it's a second query.

**Invites.** `resident.invite` mints a token and returns the shareable link. It
expires after 7 days. There is no re-issue action: revoke the old invite and
create a new one for the same address, which is what the UI's "Re-issue" button
does. Invites are single-use — the recipient sets a password to claim the seat.

**Offboarding** is a three-step flow: `offboard_start` creates the checklist,
`checklist_toggle` clears items, `offboard` completes it. The final step
re-checks blocking items server-side, so a stale button can't offboard someone
who still owes money. `force` overrides that for a deliberate admin override.

---

## Notices and reminders

| Action | Method | Guard | Inputs |
|---|---|---|---|
| `notice.list` | GET | auth | `limit?` |
| `notice.find` | GET | auth | `id` |
| `notice.create` | POST | auth | `title`, `body?`, `category?`, `audience?`, `audience_room_id?`, `audience_group_id?` |
| `notice.pin` | POST | admin | `id`, `pinned`, `until?` |
| `notice.delete` | POST | auth | `id` |
| `notice.read` | POST | auth | `id` |
| `notice.read_all` | POST | auth | → `{marked}` |
| `reminder.inbox` | GET | auth | `limit?` |
| `reminder.read` | POST | auth | `id` |
| `reminder.read_all` | POST | auth | → `{marked}` |

`audience` is one of `everyone`, `admins`, `room`, `duty_group`. `admins` silently
falls back to `everyone` for non-admins. Categories: `general`, `maintenance`,
`billing`, `event`, `alert`.

Notice rows carry `is_read`, `is_pinned_active`, `ago`, `excerpt` and
`audience_label` so the list needs no follow-up calls. Deleting is permitted for
the author or any admin; the service enforces this, not the UI.

---

## Notes for integrators

- Prefer `expense.count` over fetching `limit=999`. It takes identical filters.
- `balance.simplify` with `strategy=auto` is what the UI calls; it picks between
  greedy and optimal based on the size of the balance set.
- `chore.generate` and `chore.rotate` are idempotent for a given window — they
  overwrite the plan rather than duplicating it.
- Every `POST` that changes money is expected to be retried safely by re-reading
  state, not by blind re-POSTing; there is no idempotency key.