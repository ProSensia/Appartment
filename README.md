# FlatMate

A shared-flat manager for a single apartment. Meal planning, chore rotation,
expense splitting, notices and resident onboarding in one PHP/MySQL app that
runs under XAMPP with no build step and no package manager.

Money is stored as `DECIMAL(12,2)` in MySQL and converted to **integer cents** by
`Money::toCents()` / `Money::toAmount()` at the app boundary, so every amount in
the JSON API is an integer. Only the presentation layer renders a currency string.

---

## Requirements

| Component | Version | Notes |
|---|---|---|
| PHP | 8.1+ | Needs `pdo_mysql`, `mbstring`, `json` |
| MySQL | 8.0+ or MariaDB 10.4+ | InnoDB, `utf8mb4` |
| Browser | Any current evergreen | Bootstrap 5 + Bootstrap Icons via CDN |

Nothing needs to be compiled. Clone into the web root and open it.

---

## Install

1. **Copy the files** into your XAMPP web root:

   ```
   C:\xampp\htdocs\flatmate
   ```

2. **Create the database.** Open the XAMPP shell (or any MySQL client) and run:

   ```sql
   CREATE DATABASE flatmate_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

   The name is arbitrary — it just has to match `'database'` in step 4.

3. **Import the schema and demo data**, in this order:

   ```
   C:\xampp\mysql\bin\mysql -u root -p flatmate_db < sql\schema.sql
   C:\xampp\mysql\bin\mysql -u root -p flatmate_db < sql\seed.sql
   ```

   From phpMyAdmin: select the `flatmate_db` database, then **Import** each file.

   Both files leave `CREATE DATABASE` / `USE` commented out, so the client must
   already be pointed at the right database. Passing a name on the command line
   (as above) is the least error-prone way; in phpMyAdmin, selecting the
   database first is what matters. If you skip this, the import "succeeds" and
   the app then queries an empty schema.

4. **Set your credentials** in `config/config.php` — that is the only file the
   app reads. (`config/database.php` is an optional shim that returns a PDO
   handle; every page loads `src/Bootstrap.php` directly instead.)

   ```php
   'db' => [
       'host'     => '127.0.0.1',
       'port'     => 3306,
       'database' => 'flatmate_db',
       'username' => 'root',
       'password' => '',          // XAMPP's default
       'charset'  => 'utf8mb4',
   ],
   ```

   `base_url` is detected from the request, so it stays `''` unless you serve
   the app from somewhere other than the URL the pages were requested from.

5. **Start Apache and MySQL** in the XAMPP control panel, then visit:

   ```
   http://localhost/flatmate/
   ```

The first request creates the session cookie and redirects to the sign-in page.

---

## Demo accounts

`sql/seed.sql` creates one apartment ("Sunrise Apartments — Block C", Dhaka),
seven user accounts, six rooms, chore areas, two weeks of meal plans and 20
expenses with fully-split shares. Dates are generated relative to `CURDATE()`,
so the dashboard always looks current.

| Role | Email | Password |
|---|---|---|
| Admin | `aisha@flatmate.test` | `admin123` |
| Resident | `rakib@flatmate.test` | `password123` |
| Resident | `nabila@flatmate.test` | `password123` |
| Resident | `tanha@flatmate.test` | `password123` |
| Resident | `sabbir@flatmate.test` | `password123` |
| Resident | `maliha@flatmate.test` | `password123` |

The seventh account, `imtiaz@flatmate.test`, is deliberately left as a **pending
invite** with an unfinished offboarding checklist, so the invite-reissue and
offboarding screens have something real to show. It cannot sign in until the
invite is accepted.

Sign in as **Aisha** to see the admin-only affordances (invites, rooms and duty
groups, resident status changes, offboarding, chore area editing).

**The database name must match.** `config/config.php` sets `db.database`, and
that has to be the same database you imported into. Both SQL files have their
`CREATE DATABASE` / `USE` lines commented out, so nothing overrides your
selection — select your database in phpMyAdmin first, or pass it on the CLI:

```
mysql -u YOUR_USER -p YOUR_DB < sql/schema.sql
mysql -u YOUR_USER -p YOUR_DB < sql/seed.sql
```

If sign-in reports a wrong email *or* password for an address you know is
seeded, the usual cause is an empty database: the import ran against a different
schema than the app is reading.

> Delete these accounts, or the whole `flatmate` database, before using this for
> anything real.

---

## Verifying the install

Two independent checks, neither of which needs a working browser.

**In the app.** `src/SelfTest.php` exercises the three pure algorithms — debt
simplification, chore rotation and money parsing — against known inputs. It is
not its own endpoint: adding `?verify=1` to any authenticated read wraps that
endpoint's normal response alongside the suite.

```
http://localhost/flatmate/api/index.php?action=auth.me&verify=1
```

The body is `{"result": {...}, "verification": {"ok": true, "debt": {...},
"duty": {...}, "money": {...}}}`. `verify=1` is required so the suite cannot be
triggered from a random page, and `public` routes ignore it. Run this after any
change to those three classes; a failure names the exact check that broke.

**From a terminal.** Static checks need Python 3 but no PHP and no database:

```
python tests/run_checks.py
```

Two of them are more useful with extra tools, and skip cleanly without them:
the PHP syntax check uses `php -l` if PHP is installed, otherwise the optional
`php-parser` devDependency (`npm install` inside `tests/`); the JS check uses
`node --check`.

They verify that the SQL parses and that every FK, INSERT column and view
reference resolves; that no `INSERT` has a column/value count mismatch
(MySQL `#1136`) and no query uses a construct MySQL rejects at runtime; that
every PHP file both loads (BOM / misplaced `declare`) and compiles; that every
named PDO placeholder is bound and none is duplicated; that cross-class
references resolve; that route handlers are wired sane; and that every
front-end JS file parses.

---

## What's in the box

| Page | What it does |
|---|---|
| `index.php` | Dashboard: balances, today's duties, meal preview, reminders |
| `meals.php` | Weekly plan, suggest/vote/accept, participation, groceries |
| `chores.php` | Rotation board, personal duties, fairness, verification, areas |
| `expenses.php` | Ledger, who-owes-whom, settlement recording, audit trail |
| `notices.php` | Announcement board with category filters and unread tracking |
| `residents.php` | Roster, invites, room/group assignment, offboarding |
| `join.php` | Invitation acceptance — sets a password to claim a seat |

Admin-only features are hidden in the UI *and* rejected by the API, so the
server is the only place that matters for permissions.

---

## Project layout

```
config/     config.php (credentials + app settings); database.php (optional PDO shim)
src/        All backend logic. One class per file, no framework.
api/        index.php — the single JSON endpoint and its route table
includes/   head.php / foot.php — shared page shell and navigation
assets/     css/style.css, js/*.js — no framework, no bundler
sql/        schema.sql, seed.sql
tests/      Static checks and the algorithm self-test
docs/       API.md, ARCHITECTURE.md
```

PHP is server-rendered for the page skeleton and JSON for everything dynamic.
There is one JS file per page, each an IIFE with a matching `DOMContentLoaded`
bootstrap, so a page only downloads the controller it needs.

---

## Things worth knowing before you change the code

- **Money is DECIMAL in the DB, cents in the API.** Columns are named plainly
  (`amount`, `share_amount`, `rent_amount`) and typed `DECIMAL(12,2)`. Only the
  JSON API uses `_cents` suffixes, and every such value is an integer.
  `Money::format()` is the only place that produces a string with a currency
  symbol. Never sum cents in SQL — `SUM()` over `DECIMAL` is already exact.
- **One named placeholder per use.** Native prepared statements reject a
  repeated `:name`, so queries that need the same value twice use `:u1`/`:u2`.
  `tests/check_bindings.py` and `check_placeholders.py` enforce both halves of
  this rule.
- **Views pass three shapes deliberately.** `resident.roster` is bucketed
  (`{rooms, duty_groups, residents}`), `chore.mine` is grouped by urgency, and
  `meal.week` is `{plan, grid, stats}`. Controllers index into them directly; a
  flat list would have hidden which of the three shapes is expected.
- **Chores ignore meal participation.** Someone not cooking still has duties.
- **Rotation is deterministic.** `daily` uses `(daysSinceEpoch + rotation_offset) % poolSize`
  and `weekly` uses `(floor(daysSinceEpoch / 7) + rotation_offset) % poolSize`, so
  the same person comes up on the same date on every device without storing an
  assignment per day.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| "Access denied for user 'root'@'localhost'" | `db.password` in `config/config.php` doesn't match your MySQL. XAMPP's default is empty. |
| Import "succeeds" but every page is empty / errors on a missing table | The client wasn't pointed at a database. Both SQL files have `CREATE DATABASE`/`USE` commented out — pass the name on the CLI or select it in phpMyAdmin first. |
| `#1136 Column count doesn't match value count` | An `INSERT` lists more columns than values. `tests/check_insert_arity.py` catches this statically; run `python tests\run_checks.py`. |
| `strict_types declaration must be the very first statement` | The file starts with a UTF-8 BOM (invisible in most editors). Re-save the file as UTF-8 **without BOM**; `tests/check_php_preamble.py` finds every affected file. |
| After sign-in the URL is `index.php.php` (or similar) | A redirect target already ended in `.php` and had it appended again. Route every `?next=` value through `safe_page()` in `src/Bootstrap.php`, which rebuilds `name.php` from an allow-list. |
| A red "Request failed (500)" toast on every page | The API returned a non-JSON 500, meaning a PHP fatal before the router's own error handling — almost always a parse error. Run `python tests\run_checks.py` (`check_php_syntax.py` names the file and line). The endpoint also now returns the real message as JSON instead of a blank 500. |
| Dashboard/ledger returns 400 `balances must sum to 0` | `vw_balance_sheet` counted a user twice when they both sent and received a settlement. Recreate the view from `sql/schema.sql` (see the note under the view). Ensure your DB has the fixed definition. |
| Blank page, no output | PHP error display is off. Check `php.ini` `display_errors`, or read `error_log`. |
| "could not find driver" | Enable `extension=pdo_mysql` in `php.ini` and restart Apache. |
| 404 on every page | Files are outside the web root, or `base_url()` doesn't match the folder name. |
| Notices/meals load but chores don't | A seeded `weekday_mask` has no match for today; that is expected mid-week. |
| Self-test returns `ok: false` | A pure algorithm regressed. The failing check is named in the `details` array. |

Further reading: [docs/API.md](docs/API.md), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).