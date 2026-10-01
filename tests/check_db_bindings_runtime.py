"""Test: Database::assertBindingsMatch() must catch exactly the three HY093 causes.

PDO reports every binding mistake as the same opaque
`SQLSTATE[HY093]: Invalid parameter number`, naming neither the statement nor the
placeholder. That is why a real one of these stayed undiagnosed: the log said
only "Database.php:64" and the failing query was reached through a helper the
page called indirectly. Database::query() now checks the bindings itself and
throws a message that names the SQL, so the assertion is worth testing properly.

Two things make that risky rather than merely useful. It runs on *every* query in
the app, so a false positive breaks working code -- and one such query really
does exist: DutyScheduler builds "WHERE id IN (?,?,?)" and binds a plain list,
which is positional and must be counted rather than name-matched. And the
repetition rule exists because a real bug shipped that way: ExpenseService's
search filter used :q three times in one statement, which PDO rejects with
emulation off and which no static checker could see, because the clause was only
concatenated into the SQL at runtime.

So the port must reproduce every case the codebase actually uses, and must stay
quiet on all of them.
"""
import re
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

ROOT = Path(__file__).resolve().parent.parent


def strip_literals(sql):
    sql = re.sub(r"'(?:[^'\\]|\\.)*'", " ", sql)
    sql = re.sub(r'"(?:[^"\\]|\\.)*"', " ", sql)
    sql = re.sub(r"--[^\n]*", " ", sql)
    return re.sub(r"/\*.*?\*/", " ", sql, flags=re.S)


def check(sql, params):
    """Mirror of Database::assertBindingsMatch(); returns None or a message."""
    if not params:
        return None
    clean = strip_literals(sql)
    positional = clean.count("?")

    if positional > 0:
        named = sorted(set(re.findall(r":([a-zA-Z_]\w*)", clean)))
        if named:
            return (f"both {positional} positional ? marker(s) and named "
                    f"placeholder(s) ({', '.join(named)})")
        if len(params) != positional:
            return (f"{positional} positional ? marker(s) but "
                    f"{len(params)} value(s) bound")
        return None

    declared = re.findall(r":([a-zA-Z_]\w*)", clean)
    if not declared:
        return (f"{len(params)} value(s) bound ({', '.join(sorted(params))}) "
                f"but the query declares no placeholders")

    keys = list(params)
    unbound = sorted(set(declared) - set(keys))
    unused = sorted(set(keys) - set(declared))
    seen, repeated = set(), []
    for name in declared:
        if name in seen:
            repeated.append(name)
        else:
            seen.add(name)

    parts = []
    if repeated:
        parts.append("repeated placeholder(s): "
                     + ", ".join(f":{n}" for n in sorted(set(repeated))))
    if unbound:
        parts.append("declared but not bound: "
                     + ", ".join(f":{n}" for n in unbound))
    if unused:
        parts.append("bound but not declared: " + ", ".join(unused))
    return "; ".join(parts) if parts else None


problems = []

# ---- (1) everything the codebase actually does must pass ------------------
# Shapes taken from the real code, including the positional list.
ok_cases = [
    ("SELECT * FROM users WHERE id = :u", {"u": 1}),
    ("SELECT * FROM vw_balance_sheet WHERE apartment_id = :a "
     "ORDER BY net_balance DESC, full_name", {"a": 7}),
    # ExpenseService: the split that fixed the duplicate :to
    ("WHERE p.week_start <= :to1 AND p.week_start >= DATE_SUB(:to2, INTERVAL 6 DAY)",
     {"to1": "2026-10-01", "to2": "2026-10-01"}),
    # the :q fix
    ("(e.title LIKE :q1 OR e.description LIKE :q2 OR e.reference_no LIKE :q3)",
     {"q1": "%a%", "q2": "%a%", "q3": "%a%"}),
    # string literals that look like placeholders must be ignored
    ('SELECT * FROM users WHERE status IN ("active","invited") AND id = :a', {"a": 1}),
    ("SELECT id FROM users WHERE note = ':nope' AND id = :id", {"id": 4}),
    # DutyScheduler: positional ? bound to a numeric list
    ("SELECT id, full_name FROM users WHERE id IN (?,?,?)", {0: 1, 1: 2, 2: 3}),
    ("SELECT id FROM users WHERE id = :id", {"id": 4}),
    # no params at all
    ("SELECT COUNT(*) FROM announcements", {}),
]
for sql, params in ok_cases:
    result = check(sql, params)
    if result is not None:
        problems.append(f"false positive on valid query: {result} -- {sql[:70]}")

# ---- (2) each real failure mode must be named ----------------------------
bad_cases = [
    # repeated marker: the shipped ExpenseService bug
    ("(e.title LIKE :q OR e.description LIKE :q OR e.reference_no LIKE :q)",
     {"q": "%a%"}, "repeated"),
    # declared but not bound
    ("SELECT * FROM expenses WHERE apartment_id = :a AND is_deleted = 0",
     {}, None),  # no params -> PHP returns early, so this must NOT be flagged
    ("SELECT * FROM expenses WHERE apartment_id = :a AND is_deleted = 0",
     {"b": 1}, "declared but not bound"),
    # bound but not declared: the original ExpenseService::countFor bug
    ("SELECT COUNT(*) FROM expenses e WHERE e.apartment_id = :a",
     {"a": 1, "mine": 3}, "bound but not declared"),
    # no placeholders but values bound
    ("SELECT COUNT(*) FROM notices", {"a": 1}, "declares no placeholders"),
    # positional count mismatch
    ("SELECT id FROM users WHERE id IN (?,?)", {0: 1}, "positional ? marker(s) but"),
    # mixing the two styles
    ("SELECT id FROM users WHERE id IN (?) AND role = :r", {0: 1, "r": "admin"},
     "both 1 positional"),
]
for sql, params, expect in bad_cases:
    result = check(sql, params)
    if expect is None:
        if result is not None:
            problems.append(f"should be silent but flagged: {result} -- {sql[:70]}")
        continue
    if result is None:
        problems.append(f"missed a {expect} case: {sql[:70]}")
    elif expect not in str(result):
        problems.append(f"wrong diagnosis for {expect}: got {result}")

# ---- (3) the shipped bug must be caught by the shipped check --------------
shipped = (ROOT / "src" / "ExpenseService.php").read_text(encoding="utf-8")
if ":q1" not in shipped or ":q2" not in shipped or ":q3" not in shipped:
    problems.append("ExpenseService no longer splits the search placeholder into "
                    ":q1/:q2/:q3")
else:
    clause = next(l for l in shipped.splitlines() if "LIKE :q1" in l)
    names = set(re.findall(r":(\w+)", clause))
    if len(names) != 3:
        problems.append(f"expected three distinct search placeholders, got {names}")

if problems:
    print(f"\nFAIL - {len(problems)} binding-assertion problem(s):\n")
    for p in problems:
        print(f"  {p}")
    sys.exit(1)

print(f"OK - binding assertion: {len(ok_cases)} real query shapes accepted, "
      f"{len(bad_cases) - 1} failure modes diagnosed, no false positives")