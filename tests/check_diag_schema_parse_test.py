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
for needle, why in [
    ("$full[1] + strlen($full[0])", "where the table body starts"),
    ("$depth = 1;", "initial paren depth"),
    ("'/^`(\\w+)`\\s/'", "the backtick-quoted column filter"),
]:
    if needle not in source:
        problems.append(f"src/Diag.php no longer contains {needle} ({why}) -- "
                        f"update this port")

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