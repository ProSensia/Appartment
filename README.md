# FlatMate

A shared-flat manager for a single apartment. Meal planning, chore rotation,
expense splitting, notices and resident onboarding in one PHP/MySQL app that
runs under XAMPP with no build step and no package manager.

Money is tracked in **integer cents** end to end; only the presentation layer
converts to a currency string.

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
   CREATE DATABASE flatmate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. **Import the schema and demo data**, in this order:

   ```
   C:\xampp\mysql\bin\mysql -u root -p flatmate < sql\schema.sql
   C:\xampp\mysql\bin\mysql -u root -p flatmate < sql\seed.sql
   ```

   From phpMyAdmin: select the `flatmate` database, then **Import** each file.

4. **Set your credentials** in `config/database.php`:

   ```php
   return [
       'host'     => '127.0.0.1',
       'port'     => 3306,
       'database' => 'flatmate',
       'username' => 'root',
       'password' => '',          // XAMPP's default
       'charset'  => 'utf8mb4',
   ];
   ```

5. **Start Apache and MySQL** in the XAMPP control panel, then visit:

   ```
   http://localhost/flatmate/
   ```

The first request creates the session cookie and redirects to the sign-in page.

---

## Demo accounts

`sql/seed.sql` creates one apartment ("Maple Court"), five residents, six rooms,
chore areas, meals and expenses.

| Role | Email | Password |
|---|---|---|
| Admin | `aisha@flatmate.test` | `admin123` |
| Resident | `rakib@flatmate.test` | `password123` |
| Resident | `nabila@flatmate.test` | `password123` |
| Resident | `tanha@flatmate.test` | `password123` |
| Resident | `sabbir@flatmate.test` | `password123` |
| Resident | `maliha@flatmate.test` | `password123` |

Sign in as **Aisha** to see the admin-only affordances (invites, rooms and duty
groups, resident status changes, offboarding, chore area editing).

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

They verify that the SQL parses and that every FK, INSERT column and view
reference resolves; that every named PDO placeholder is bound and none is
duplicated; that cross-class references resolve; that route handlers are wired
sane; and that every front-end JS file parses.

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
config/     config.php (app settings), database.php (PDO credentials)
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

- **Money is cents.** Columns end in `_cents`, and every value crossing into or
  out of the API is an integer. `Money::format()` is the only place that
  produces a string with a currency symbol.
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
| "Access denied for user 'root'@'localhost'" | Password in `config/database.php` doesn't match your MySQL. XAMPP's default is empty. |
| Blank page, no output | PHP error display is off. Check `php.ini` `display_errors`, or read `error_log`. |
| "could not find driver" | Enable `extension=pdo_mysql` in `php.ini` and restart Apache. |
| 404 on every page | Files are outside the web root, or `base_url()` doesn't match the folder name. |
| Notices/meals load but chores don't | A seeded `weekday_mask` has no match for today; that is expected mid-week. |
| Self-test returns `ok: false` | A pure algorithm regressed. The failing check is named in the `details` array. |

Further reading: [docs/API.md](docs/API.md), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).