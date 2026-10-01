"""Cross-check: Diag's PHP schema parser must agree with check_sql_columns.py.

src/Diag.php re-implements schema.sql parsing in PHP so the runtime report can
diff a live database against the schema. Two parsers of the same file drifting
apart is a silent failure mode: check_sql_columns.py is what keeps 1054 column
errors out of the codebase, and Diag's copy is what tells you whether your
database needs an upgrade. If the PHP one quietly stopped finding columns, the
report would list half the schema as missing and send you off to run a migration
you do not need -- a confident wrong answer, which is the worst kind.

So the PHP parser's algorithm is ported here and diffed against the Python one,
which was written independently and is already proven against real bugs. Both
must yield identical column sets for every table.

The port cannot be trusted if src/Diag.php is later rewritten, so the test also
asserts that the three lines the port depends on are still present in the source.
If they are not, update the port.

Two ports of this parser were written before this test existed, differing only in
where they began the paren scan (the table-name group, or the end of the full
match). Both produce identical column sets, because depth counting self-corrects
once the CREATE TABLE's own paren is seen; the full-match form is kept here as
the one that reads as what it means.
"""
import re
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

ROOT = Path(__file__).resolve().parent.parent
SCHEMA = ROOT / "sql" / "schema.sql"
DIAG = ROOT / "src" / "Diag.php"

sys.path.insert(0, str(ROOT / "tests"))
import check_sql_columns as reference  # noqa: E402


def _columns(body):
    """Mirror of the column loop in Diag::parseSchemaTables()."""
    cols = set()
    for line in body.splitlines():
        line = line.strip()
        if not line:
            continue
        if re.match(r"^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN|CHECK|--|/|\*)",
                    line, re.I):
            continue
        # The real parser requires a backtick-quoted name, which is what keeps
        # "  ON DELETE CASCADE" and "  REFERENCES x(y)" out of the column list.
        m = re.match(r"^`(\w+)`\s", line)
        if m:
            cols.add(m.group(1).lower())
    return cols


def _scan(sql, start, depth):
    i, n = start, len(sql)
    while i < n:
        if sql[i] == "(":
            depth += 1
        elif sql[i] == ")":
            depth -= 1
            if depth == 0:
                break
        i += 1
    return sql[start:i]


def ported_parse(sql):
    """Mirror of Diag::parseSchemaTables()."""
    pattern = re.compile(r"CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?(\w+)`?\s*\(", re.I)
    tables = {}
    for m in pattern.finditer(sql):
        tables[m.group(1).lower()] = _columns(
            _scan(sql, m.start() + len(m.group(0)), 1))
    return tables


problems = []

# ---- the port must still describe the PHP that is actually shipped ---------
source = DIAG.read_text(encoding="utf-8")
# Comments are stripped before the "did it regress?" greps, so the explanation of
# the FETCH_KEY_PAIR mistake in Diag's docblock does not trip the guard below.
code_only = re.sub(r"/\*.*?\*/", " ", source, flags=re.S)
code_only = re.sub(r"^\s*//.*$", " ", code_only, flags=re.M)
for needle, why in [
    ("$full[1] + strlen($full[0])", "where the table body starts"),
    ("$depth = 1;", "initial paren depth"),
    ("'/^`(\\w+)`\\s/'", "the backtick-quoted column filter"),
    ("function diffColumns", "the drift comparison"),
    ("PDO::FETCH_ASSOC", "the information_schema fetch mode"),
]:
    if needle not in source:
        problems.append(f"src/Diag.php no longer contains {needle} ({why}) -- "
                        f"update this port")

# The failure this whole exercise exists to prevent: collapsing (TABLE_NAME,
# COLUMN_NAME) rows with FETCH_KEY_PAIR destroys every column but one per table.
if "FETCH_KEY_PAIR" in code_only:
    problems.append("src/Diag.php uses PDO::FETCH_KEY_PAIR again -- it collapses "
                    "information_schema rows and reports every column as missing")

if not problems:
    sql = SCHEMA.read_text(encoding="utf-8")
    got = ported_parse(sql)
    want = reference.load_columns()

    missing = set(want) - set(got)
    if missing:
        problems.append(f"tables the ported parser never found: {sorted(missing)}")

    for table in sorted(set(want) & set(got)):
        diff = want[table] ^ got[table]
        if diff:
            problems.append(f"{table}: column mismatch {sorted(diff)}")

    # A parser that finds nothing would agree with nothing; require real output.
    if len(got) < 10:
        problems.append(f"ported parser only found {len(got)} tables -- "
                        f"the comparison above would be vacuous")
    # And the backtick filter must not be so lax that constraints leak in.
    for table, cols in got.items():
        leaked = cols & {"references", "constraint", "check", "unique", "key"}
        if leaked:
            problems.append(f"{table}: constraint keywords counted as columns: "
                            f"{sorted(leaked)}")

if problems:
    print(f"\nFAIL - {len(problems)} schema-parse problem(s):\n")
    for p in problems:
        print(f"  {p}")
    sys.exit(1)

total = sum(len(c) for c in want.values())
print(f"OK - Diag's schema parser agrees with check_sql_columns.py on "
      f"{len(want)} tables / {total} columns")


# --------------------------------------------------------------------------
# Diag::diffColumns() -- the comparison that actually answers "is my DB stale?"
# --------------------------------------------------------------------------

def diff_columns(expected, rows):
    """Mirror of Diag::diffColumns()."""
    live, live_tables = set(), set()
    for row in rows:
        table = (row.get("TABLE_NAME") or "").lower()
        column = (row.get("COLUMN_NAME") or "").lower()
        if not table:
            continue
        live_tables.add(table)
        if column:
            live.add(f"{table}.{column}")

    missing_tables, missing_columns = [], []
    for table, cols in expected.items():
        table = table.lower()
        if table not in live_tables:
            missing_tables.append(table)
            continue
        for col in cols:
            if f"{table}.{col.lower()}" not in live:
                missing_columns.append(f"{table}.{col.lower()}")

    extra = []
    for table_col in live:
        table, _, column = table_col.partition(".")
        if table not in expected:
            continue
        if column not in {c.lower() for c in expected[table]}:
            extra.append(table_col)
    extra.sort()

    return {
        "missing_tables": missing_tables,
        "missing_columns": missing_columns,
        "extra_columns": extra,
        "present_tables": len(live_tables),
    }


def rows_for(expected):
    """information_schema.COLUMNS rows for a database matching `expected`."""
    return [{"TABLE_NAME": t, "COLUMN_NAME": c}
            for t, cols in expected.items() for c in cols]


# ---- the healthy case must report nothing --------------------------------
in_sync = diff_columns(want, rows_for(want))
for key in ("missing_tables", "missing_columns", "extra_columns"):
    if in_sync[key]:
        problems.append(f"a database identical to schema.sql reported "
                        f"{len(in_sync[key])} {key}: {in_sync[key][:5]}")

# This is the regression that shipped: FETCH_KEY_PAIR collapses the rows to one
# per table, so the lookup map ends up keyed by table name and "table.column"
# never matches. Assert it is catastrophic rather than exact, since the exact
# count depends on the schema.
collapsed = {}
for row in rows_for(want):
    collapsed[row["TABLE_NAME"]] = row["COLUMN_NAME"]     # PDO::FETCH_KEY_PAIR
legacy = diff_columns(want, [{"TABLE_NAME": k, "COLUMN_NAME": v}
                             for k, v in collapsed.items()])
collapsed_missing = len(legacy["missing_columns"])
if collapsed_missing != sum(len(c) for c in want.values()) - len(want):
    problems.append(f"the collapsed-row case does not reproduce the "
                    f"everything-is-missing failure (got {collapsed_missing}) -- "
                    f"the regression test is not testing the bug")

# ---- and a genuinely drifted database must be reported precisely ---------
drifted_rows = [r for r in rows_for(want)
                if r["COLUMN_NAME"] != "week_starts_on"]        # one column gone
drifted_rows = [r for r in drifted_rows
                if r["TABLE_NAME"] != "suggestion_votes"]       # one table gone
drifted_rows.append({"TABLE_NAME": "chore_tasks",
                     "COLUMN_NAME": "signed_off_by"})           # one extra column
drifted_rows.append({"TABLE_NAME": "ghost_table", "COLUMN_NAME": "id"})
drifted = diff_columns(want, drifted_rows)

expected_diff = {
    "missing_tables": ["suggestion_votes"],
    "missing_columns": ["apartments.week_starts_on"],
    "extra_columns": ["chore_tasks.signed_off_by"],
}
for key, want_list in expected_diff.items():
    got = drifted[key]
    if got != want_list:
        problems.append(f"drift test: {key} should be {want_list}, got {got}")
# suggestion_votes was dropped and ghost_table added, so the live count is
# unchanged. ghost_table is live but unknown to schema.sql: counted as present,
# and reported by neither missing nor extra -- "the app created a table the
# schema doesn't describe" is a different finding from "a column is missing".
if drifted["present_tables"] != len(want):
    problems.append(f"drift test: present_tables should be {len(want)}, "
                    f"got {drifted['present_tables']}")

# ---- case must not matter: MySQL returns names as declared ----------------
shouty = [{"TABLE_NAME": r["TABLE_NAME"].upper(), "COLUMN_NAME": r["COLUMN_NAME"].upper()}
          for r in rows_for(want)]
if diff_columns(want, shouty)["missing_columns"]:
    problems.append("an all-uppercase information_schema was treated as out of sync")

if problems:
    print(f"\nFAIL - {len(problems)} schema-parse problem(s):\n")
    for p in problems:
        print(f"  {p}")
    sys.exit(1)

print(f"OK - in-sync reports clean; real drift reports exactly "
      f"{len(expected_diff['missing_columns'])} column, "
      f"{len(expected_diff['missing_tables'])} table, "
      f"{len(expected_diff['extra_columns'])} extra; case-insensitive "
      f"(the collapsed-row bug reported {collapsed_missing} false positives)")