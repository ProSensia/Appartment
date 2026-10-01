# Architecture

How FlatMate is put together, and why a few decisions went the way they did.

---

## Shape

```
Browser
  |
  |-- GET  /chores.php  ............  PHP renders the page skeleton
  |-- GET  /assets/js/chores.js .....  one controller per page, no bundler
  |-- GET  /api/index.php?action=... JSON for everything dynamic
  |
  v
api/index.php  -- route table: [verb, guard, handler, required inputs]
  |
  v
src/*.php  -- one class per file, static methods, no framework
  |
  v
src/Database.php  -- PDO wrapper, named params only
  |
  v
MySQL / MariaDB
```

Server-rendered skeleton plus JSON for data. There is no SPA router, no build
step, and no `node_modules`, so the whole thing works from a fresh XAMPP install
by copying files.

### Why no framework

The app has 69 endpoints and a handful of pages. A framework would add a
routing layer, a dependency tree and a deployment story to replace roughly what
`api/index.php` does in 282 lines. The trade is that validation and auth are
hand-rolled, which is why the guard level is declared *per route in the table*
where it's auditable, rather than inferred from middleware.

---

## Request lifecycle

1. `src/Bootstrap.php` autoloads `src/`, configures the session, sets the error
   handler.
2. `ApiAuth::resolve()` accepts either a session cookie or
   `Authorization: Bearer`, and requires `status = 'active'` on the user.
3. The route table is looked up by `action`. Unknown → `404`.
4. The guard runs. `admin` checks `role`; `auth` is implied by step 2.
5. CSRF is enforced for cookie-authenticated `POST`s. Bearer requests skip it.
6. Required inputs are checked; missing ones give `422` naming each field.
7. The handler runs and its return value becomes `data`.
8. `Response::json()` serialises the envelope.

Exceptions map to codes centrally: `ValidationException` → `422` with per-field
details, `RuntimeException`/`InvalidArgumentException` → `400`, `PDOException` →
`500` with the real message only when `app.env !== 'production'`.

---

## Money

MySQL stores money as `DECIMAL(12,2)` — never `FLOAT`/`DOUBLE`, never an integer
column. `DECIMAL` is exact base-10, so `SUM()` in SQL and the
`vw_balance_sheet` view are correct without any compensating logic.

The boundary is where integers appear: `Money::toCents()` converts a
`DECIMAL` value from a query into the integer the JSON API returns (`*_cents`),
and `Money::toAmount()` converts an integer from the API or a form back into
`DECIMAL` for storage. `Money::format()` is the only place that renders a
currency string.

This is not fastidiousness. Floating point is wrong at the margins that matter
here: splitting 100.00 three ways must be 33.34 / 33.33 / 33.33, and every split
algorithm in `DebtSimplifier` assumes integer conservation — so the allocation
runs in integer cents and is written back as `DECIMAL`. `SelfTest` includes
an `odd_cents` case (3333/1111/1111/1111) precisely to pin this down.

Two derived rules that follow:

- **Sums must balance exactly.** `ExpenseService::create()` validates that splits
  conserve the total to the cent and rejects otherwise, rather than absorbing the
  remainder into a designated row.
- **Native prepared statements reject a repeated named placeholder.** Queries
  needing the same value twice use `:u1`/`:u2`. `tests/check_placeholders.py`
  fails the build on a duplicate, so this never gets forgotten. That checker
  scans string literals individually rather than whole statements, because a
  query is often assembled at runtime from `$where[]` fragments that are not
  valid SQL on their own — and that is precisely where
  `ExpenseService::filterClause()` hid a reused `:q` until someone searched.

  **Every one of these is `SQLSTATE[HY093]: Invalid parameter number`**, which
  names neither the statement nor the placeholder, so a single unbound name can
  hide in a helper several call layers away. `Database::query()` therefore checks
  bindings itself on every call and throws a `RuntimeException` naming the SQL and
  the specific problem (repeated / unbound / undeclared), because the alternative
  is a log line reading only `Database.php:64`. Positional statements are counted
  rather than name-matched, since `DutyScheduler` legitimately uses `IN (?,?,?)`
  bound to a plain list.

---

## Balances and debt simplification

`BalanceEngine` derives each resident's net position from splits minus
settlements. It never stores a running balance, because a stored balance is a
denormalisation that goes stale the moment a row is edited or deleted.

`DebtSimplifier` reduces the net positions to the minimum useful set of
transfers, with three strategies:

| Strategy | Approach |
|---|---|
| `simplified()` | Collapse into a hub, then settle through it |
| `greedy()` | Two-pointer, largest debtor against largest creditor |
| `optimal()` | DFS over pairings, keeping the shortest transfer list |
| `auto` (default) | Greedy above 12 balances, else optimal |

`optimal()` is exponential, hence `OPPOSITE_SIGN_SEARCH_MAX = 12`. Past that,
`auto` takes the cheap path and the self-test asserts the result is never worse
than greedy, which holds because greedy is the fallback.

---

## Chore rotation

**No per-day assignment is stored.** The person on duty is *computed* from the
area's scope, which keeps the roster consistent across devices with no
synchronisation.

The pool is every active resident matching the area's scope:

| Scope | Pool |
|---|---|
| `common` | everyone |
| `group` | the duty group |
| `room` | the room |

Index into the pool, seeded by frequency:

```
days  = (date - EPOCH) / 86400                 // EPOCH = 1970-01-01
tick  = weekly ? floor(days / 7) : days
index = (((tick + rotation_offset) % poolSize) + poolSize) % poolSize
```

The doubled modulo is deliberate: PHP's `%` keeps the sign of the left operand, so
a negative `(tick + offset)` — which a pre-epoch date can produce — would index
outside the pool. `positionFor()` clamps `poolSize < 1` to `0` first.

`weekly` areas also carry a `weekday_mask`, so "Saturdays only" is a bitmask
rather than a special case.

Two consequences worth knowing:

- **Chores ignore meal participation.** Someone opted out of cooking still has
  duties. Meals and chores are independent systems that happen to share the
  resident list.
- **`DutyScheduler::decorateTask()` normalises fields the UI needs** — `id`,
  `icon`, `area_name`, `is_mine`, `is_unassigned` — so controllers don't each
  re-derive them from a raw join.

---

## Meal participation

`meal_participants` stores an explicit per-meal status, `eating` or
`opting_out`. A week with no rows at all is treated as "nobody opted in", which
is what makes a freshly seeded apartment behave sensibly rather than dividing
every grocery list by zero.

`meal_based` expenses read this table: the split is proportional to who is
eating that meal, so dinner costs land on the people who ate dinner.

---

## Offboarding

A three-step flow rather than a status flag, because "moving out" has real
prerequisites:

1. `beginOffboarding()` creates a checklist of `offboarding_tasks` rows, some
   flagged blocking (outstanding dues, unreturned keys, unfinished duties).
2. `checklist_toggle()` clears items.
3. `completeOffboarding()` re-checks the blocking items **server-side** and only
   then flips the user to `offboarded`.

Step 3 is the important one. A UI button reflects the state as of the last page
load, so the server is the only place that can be trusted to refuse. `force`
exists as an explicit, logged override.

`ResidentService::roster()` returns counts only; the full checklist needs a second
query, so it lives on `resident.offboard_view`. The split is deliberate and is why
`openResident()` in the residents controller fetches both.

---

## Data shapes that are not flat

Three endpoints return **bucketed** payloads rather than one list, and this
trips up anyone who assumes a flat array:

| Endpoint | Shape |
|---|---|
| `resident.roster` | `{rooms, duty_groups, residents}` |
| `chore.mine` | `{today, upcoming, overdue, count}` |
| `meal.week` | `{plan, grid, stats}` |

A flat list would have hidden which shape was expected. Each of these exists
because the UI genuinely renders all the parts at once — a roster needs rooms to
populate a `<select>` as well as residents to populate a table.

---

## Front end

One IIFE per page, each with its own `DOMContentLoaded` bootstrap, so a page
downloads only its own controller.

- `api.js` — `get`/`post` wrappers, the envelope, `ApiError`, CSRF from
  `auth.csrf`, bearer support.
- `app.js` — the shell: toasts, nav drawer, modal helpers, confirmation,
  validation-error rendering.
- `diagnostics.js` — loads **before** both of the above (see below).

### Diagnostics

The failure mode this app kept hitting was a page that renders half a screen and
a toast that says `Request failed (500)`. Neither says which statement failed, so
each fix required guessing. Diagnostics exist to turn that into one pasteable
block.

Two halves, because the failures are of two kinds.

**Server (`src/Diag.php`).** `Diag::report()` describes the installation in one
request. The part that earns its keep is schema drift: it parses
`sql/schema.sql` off disk, asks `information_schema` what the server actually
has, and reports missing tables, missing columns and missing views by name. Every
`1054` and `1136` seen so far was the code and the database disagreeing, and
"which one is stale" is otherwise invisible from a browser. The `checks` array
then runs one representative query per feature area, so a broken statement is
reported by name rather than as a blank board — including the
`SUM(net_balance) = 0` invariant that `DebtSimplifier` depends on.

**Client (`assets/js/diagnostics.js`).** Wraps `window.fetch`, so every non-2xx
API call is captured with its action, status and response body. Wrapping `fetch`
rather than instrumenting call sites is deliberate: it is the one place that
cannot be forgotten, and it catches failures a caller deliberately swallows —
`loadReminders()` in `includes/foot.php` discards its own errors because a failing
bell should not toast every resident, which is precisely why it went unnoticed.
The response body is cloned and read on failure, because an HTML 500 page is the
most useful thing to capture and by the time `ApiError` is constructed it is gone.

Entries live in `localStorage` (last 40, so they survive a reload) and are pushed
to the server once per page load via `diag.submit`, which is the only way a page
that errored and then went blank can still be diagnosed afterwards.

Reporting is deliberately prevented from reporting on itself, because the first
version of this did and it destroyed the very evidence it existed to preserve:

- The `fetch` wrapper ignores any request whose action starts with `diag`, so a
  failing reporting channel can never spawn new entries.
- A successful submit **drains** the entries it sent. Without this the queue was
  never emptied, so every subsequent page load re-posted the same 30 entries and
  the ring log filled with duplicates until the early, interesting errors had
  rotated out.
- After three consecutive submit failures the channel is disabled for that page
  load, so a broken API is not hammered on every navigation.
- `push()` collapses an immediate repeat into a `count` instead of appending,
  and `Diag::record()` folds a repeated server-side event into `n` by rewriting
  the final line of the ring log (only ever that line, only after confirming it
  is complete and parses, under `flock`, and only within `LOG_DEDUPE_SEC`).

The net effect is that a loop produces `x47` instead of 47 lines, and the one
genuine failure stays in the buffer. `tests/check_diag_log_fold.py` exercises the
offset arithmetic against real files, because a mistake there corrupts the log.

Ordering is load-bearing: `diagnostics.js` must be first, before `api.js` and
`app.js`, or it can only observe its own button. `includes/foot.php` also calls
`Diag.mount()`.

**Two security properties, both enforced by `tests/check_diag_wiring.py`:**

- `storage/.htaccess` denies the ring log over HTTP. It lives inside the
  directory rather than only in the root `.htaccess` so it still holds when the
  root file is ignored or nginx serves the site. The log holds exception text and
  SQL fragments, which is exactly the material worth having in a bug report and
  exactly the material worth keeping off the open web.
- `.gitignore` excludes `storage/*.jsonl`, so it is not committed with the fix
  that produced it.

`Diag::scrub()` redacts anything whose key looks like a secret before it reaches
the log at all, so `db.password` and CSRF tokens cannot accumulate there. The
whole class is failure-tolerant by contract: a diagnostic tool that throws is
worse than none, so `record()` swallows its own failures and falls back to
`error_log()`.

`diag.php` is deliberately outside the app shell and reachable without a session,
since the moments you need it are exactly the moments login is broken. Signed out
it returns only `Diag::report(false)` — environment, connectivity, drift and
checks — because an anonymous visitor must not be able to read row counts or the
error-log tail.

### Delegation and the confirmation dialog

Page controllers attach delegated handlers on `document`, matching
`on(document, 'click', '[data-verb]', ...)`. This survives re-rendered lists.

That creates a subtlety the confirm dialog has to handle: `preventDefault()`
stops default action but **not** propagation, so a bubble-phase confirm handler
would let the page's own action fire before the dialog was ever shown. The
confirm interceptor is therefore registered in the **capture** phase and calls
`stopPropagation()`.

On confirm it re-dispatches the click with a one-shot `data-fm-confirming="1"`
marker, cleared immediately afterwards — `el.click()` dispatches synchronously, so
the marker exists only for that redispatch and a persistent button still asks
again next time. (An earlier version used a `WeakSet`, which was one-shot forever
and would have silently skipped confirmation on every repeat click.)

### HTML form element access

Form controls are read through `form.elements.namedItem(name)`, never
`form[name]`. `HTMLFormElement` already has properties like `method`, `length`
and `action`, so `form.method` returns the string `"get"` rather than the
`<select>` — a settlement form that names a field `method` would otherwise send
its method as `"get"`.

---

## Views

Aggregates live in SQL views so they aren't duplicated across services:

| View | Used by |
|---|---|
| `vw_balance_sheet` | `BalanceEngine` |
| `vw_meal_coverage` | `MealService` (x2, incl. `meal_based` splits) |
| `vw_today_chores` | **nothing** |

`vw_today_chores` is dead weight. `DutyScheduler` computes assignments rather
than reading `chore_tasks`, so the view's `assigned_user_id` column is either
NULL or stale by construction. It's harmless — but it advertises a stored
assignment that the rotation logic deliberately does not use, which is exactly
the misconception this doc keeps having to correct. Drop it, or rewrite it to
expose the *computed* duty for today if a wide query is ever needed.

---

## Testing

Three layers, cheapest first.

**1. Static checks** (`python tests/run_checks.py`) — no PHP or database needed,
which is why they exist: neither was available on the machine that built this.

| Script | Catches |
|---|---|
| `validate_sql.py` | SQL that won't parse; dangling FK/INSERT/view references |
| `check_insert_arity.py` | MySQL `#1136` — an INSERT whose value count doesn't match its column count |
| `check_insert_arity_test.py` | Self-test proving the arity checker still fails on a seeded bug |
| `check_sql_restrictions.py` | Subquery `LIMIT`/`OFFSET` outer references; `INSERT .. SELECT` on its own target (error 1093); joined `UNION ALL` of aggregates that can multiply rows |
| `check_sql_columns.py` | `SQLSTATE[42S22]` / error 1054 - an `alias.column` reference, or an `insert()`/`update()` payload key, that names a column the schema does not have |
| `check_sql_columns_test.py` | Self-test proving the column checker still fails on the three seeded bugs |
| `check_php_preamble.py` | A UTF-8 BOM or stray output before `declare(strict_types=1)` |
| `check_php_syntax.py` | PHP that does not compile at all (`php -l`, or Node php-parser) |
| `check_bindings.py` | PDO placeholders with no bound value, **and** bound names the statement never declares (both `HY093`) |
| `check_placeholders.py` | A named placeholder used twice (per string literal, so runtime-assembled clauses are covered) |
| `check_db_bindings_runtime.py` | `Database::query()`'s runtime binding assertion rejecting a valid query, or missing an `HY093` cause |
| `check_diag_log_fold.py` | The ring log losing or corrupting entries while folding repeats |
| `check_references.py` | Calls to functions/classes that don't exist |
| `check_routes.py` | Route handlers wired as arrays instead of callables |
| `check_void_returns.py` | Reading the result of a `: void` function — a fatal at runtime that `php -l` cannot see |
| `check_void_returns_test.py` | Self-test proving the void-return checker still catches a reintroduced misuse |
| `check_diag_wiring.py` | The diagnostics ring log being web-readable, committable, or the client collector loading too late to see anything |
| `check_diag_wiring_test.py` | Self-test proving the wiring checker still fails on a leaky setup |
| `check_diag_schema_parse_test.py` | Diagnostics schema diff parser: covers the collapsed `PDO::FETCH_KEY_PAIR` false positive and actual drift |
| `check_js.py` | Front-end JS that doesn't parse |

`check_references.py` has to strip PHP out of mixed `.php` files before scanning,
or it starts reporting HTML attributes and JavaScript strings as missing
functions.

`check_insert_arity.py` is a hand-rolled tokenizer because the failure it
guards is invisible to a plain parse: `INSERT INTO t (a,b,c) SELECT x, y` is
syntactically fine and only explodes on the server. It tracks string, backtick,
comment and paren state, and stops each statement at its terminating semicolon.
The self-test re-introduces the real historical bug (a stray `id` column) into a
throwaway copy of `sql/` and asserts the checker rejects it — otherwise a
checker that silently matches nothing would pass forever.

`check_sql_restrictions.py` covers the other half of that problem: constructs
that parse but that MySQL refuses, so they only surface on the server. It strips
comments first so prose describing a bad pattern does not trip it. It also flags a
*silent* variant rather than a server error: a joined derived table whose body is a
top-level `UNION ALL` of aggregates. `vw_balance_sheet` grouped settlements by sender
and by receiver and joined the two branches directly, so a user who both paid and
received got two output rows. The join then multiplied their paid/owed totals and
keying results by `user_id` silently dropped the smaller row, leaving the net
balances off by whatever the lost settlement was (700 BDT with the seeded data).
`DebtSimplifier::simplify()` rejects any ledger that does not sum to zero, so the
whole dashboard came back as HTTP 400. Collapsing the union with an outer
`GROUP BY` gives one row per user, which is why the view now wraps its union in a
`SELECT ... SUM(...) ... GROUP BY uid`. The rule requires the `UNION ALL` to be at
the top level of the joined subquery, so the wrapper form passes.

`check_sql_columns.py` exists because a column typo in a query string is invisible
everywhere except on the server. All three of these shipped and each one blanked a
page with `SQLSTATE[42S22]: Column not found: 1054`:

| Query claimed | Reality |
|---|---|
| `meals.status`, `meal_plans.locked` | The two were swapped. `meals` has no `status`; `meal_plans.status` is the plan state and `meal_plans` has no `locked` |
| `meal_participants.is_cooking` | No such column was ever created. Cooking is tracked by `meals.cook_user_id`, so the aggregate was rewritten to count that |
| `p.locked AS plan_locked` in `MealService::setMenu()` | Same swap, which broke saving a menu |

It resolves each `alias.column` against the column list read out of
`sql/schema.sql`. It only checks *qualified* references, because an unqualified
column could come from any table in the query, and it skips aliases it cannot bind
to exactly one table (derived-table output aliases) rather than guessing. It also
validates the column keys of `Database::insert()`/`Database::update()` payloads.

Two PHP facts that shaped those fixes: PHP has no comma operator, and `''` does
**not** escape a quote inside a single-quoted PHP string the way it does in SQL.
A query therefore has to be a double-quoted PHP string to contain `'eating'`, which
is why the codebase writes SQL literals as `"..."` inside single-quoted PHP strings
instead. `Database::conn()` also pins the session `sql_mode`, so those literals stay
string literals even on a host that sets `ANSI_QUOTES`.

`check_php_preamble.py` exists because a UTF-8 BOM is invisible in an editor but
decodes to output, which makes `declare(strict_types=1)` fatal the instant the
file loads. Re-uploading files through a Windows tool introduced one on four
files at once (`src/Database.php`, `src/ActivityLog.php`, `src/NoticeBoard.php`,
`expenses.php`) and blanked every page with:

```
Fatal error: strict_types declaration must be the very first statement
```

Only files that actually declare `strict_types` are checked — `includes/foot.php`
is an HTML partial with no PHP opening tag and must not trip it.

`check_php_syntax.py` catches PHP that does not compile at all. The bug that
motivated it was six routes in `api/index.php` written as
`(Auth::logout(), ['ok' => true])[1]`. PHP has no comma operator, so the file
never compiled: the router itself was a parse error, so every API request
returned an HTML 500 before its own try/catch could run, and the client could
only show "Request failed (500)". No text-based check could see it because the
file never executed. It prefers the authoritative `php -l`; without a PHP binary
it uses the optional `php-parser` devDependency (`npm install` inside `tests/`),
and skips cleanly if neither is present.

**2. Algorithm self-test** (`src/SelfTest.php`) — runs in PHP against known
inputs, no fixtures:

```
GET /api/index.php?action=auth.me&verify=1
```

Covers debt simplification (including the optimal-vs-greedy bound), chore
rotation across scope/frequency/weekday combinations, and money parsing.

**3. Manual smoke test** — sign in as the seeded admin, walk each page. There is
no HTTP-level integration suite, and that remains the largest gap.

---

## Known gaps

- **No PHP or MySQL available in the build environment.** Everything above the
  static checks and the source review is unverified at runtime. `php -l` and a
  live walkthrough are the first things to do on a machine that has both.
- **No integration tests.** The self-test covers pure functions only; no test
  exercises a service against a real database.
- **Bearer tokens are long-lived** (90 days) and can be minted and revoked by
  `ApiAuth`, but nothing wires either to the UI — there is no token management
  screen.
- **Sessions table is shared** with "remember me", so revoking API tokens and
  signing out everywhere are the same operation — more aggressive than either
  alone.