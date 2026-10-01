"""
Verify that every SQL string in the PHP sources only names columns that exist.

MySQL reports a bad column reference as SQLSTATE[42S22] / error 1054 at runtime,
which is exactly how the dashboard, meals and chores pages were found broken: an
alias was swapped (m.status / p.locked) and a column that never existed was
invented (meal_participants.is_cooking). None of those are visible in an editor,
because the string parses fine as PHP and as SQL.

So: read the real column list per table out of sql/schema.sql, then walk every
SQL literal in src/, api/, config/ and includes/ and resolve each `alias.column`
reference against the table that alias is bound to.

Rules, and why they are safe:
  * Only *aliased/qualified* references are checked. An unqualified column could
    legally come from any table in the query, so guessing invites false alarms.
  * A reference through an alias we cannot resolve (a derived table's output
    alias, or a name that appears in an unrelated subquery) is skipped rather
    than reported.
  * One alias bound to two different tables in the same statement is ambiguous,
    so it is skipped too.
  * `db.name` style references (where the prefix is not a table alias) are skipped.

Also checks the column keys of Database::insert()/Database::update() payloads,
which is where a misspelled column is otherwise invisible until the write fails.
"""
import re
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

ROOT = Path(__file__).resolve().parent.parent
SCHEMA = ROOT / "sql" / "schema.sql"
SRC_DIRS = ["src", "api", "config", "includes"]

STRING_RE = re.compile(r"'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\"", re.S)

# SQL keywords, functions and clause words that can appear before a dot and are
# never a table alias.
NOT_AN_ALIAS = {
    "select", "insert", "update", "delete", "from", "where", "join", "left",
    "right", "inner", "outer", "cross", "on", "and", "or", "not", "null", "as",
    "by", "group", "order", "having", "limit", "offset", "union", "all", "case",
    "when", "then", "else", "end", "in", "is", "between", "like", "distinct",
    "count", "sum", "avg", "min", "max", "coalesce", "ifnull", "if", "field",
    "date_format", "date_sub", "date_add", "dayofweek", "curdate", "now",
    "concat", "round", "abs", "greatest", "least", "cast", "convert", "values",
    "duplicate", "key", "default", "table", "index", "primary", "foreign",
    "references", "constraint", "unique", "check", "cascade", "desc", "asc",
    "separator", "interval", "day", "using", "straight_join", "for", "share",
    "mode", "exists", "any", "some", "row", "rows", "range", "partition",
}


# --------------------------------------------------------------------------
# schema.sql -> {table: {columns}}
# --------------------------------------------------------------------------

def load_columns():
    text = SCHEMA.read_text(encoding="utf-8")
    tables = {}
    for m in re.finditer(r"CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?(\w+)`?\s*\(", text, re.I):
        name = m.group(1).lower()
        start = m.end()
        depth, i, n = 1, start, len(text)
        while i < n and depth:
            if text[i] == "(":
                depth += 1
            elif text[i] == ")":
                depth -= 1
            i += 1
        body = text[start:i - 1]

        cols = set()
        for line in body.split("\n"):
            line = line.strip()
            if not line or line.startswith(("PRIMARY", "UNIQUE", "KEY", "INDEX",
                                            "CONSTRAINT", "FOREIGN", "CHECK",
                                            "--", "/*", "*", ")")):
                continue
            cm = re.match(r"`(\w+)`\s", line)
            if cm:
                cols.add(cm.group(1).lower())
        tables[name] = cols
    return tables


def load_views():
    """View names, so a query against one is not reported as a missing table."""
    if not SCHEMA.exists():
        return set()
    text = SCHEMA.read_text(encoding="utf-8")
    return {m.lower() for m in re.findall(
        r"CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM[^=]*\s+)?(?:DEFINER[^=]*\s+)?"
        r"(?:SQL\s+SECURITY\s+\w+\s+)?VIEW\s+`?(\w+)`?", text, re.I)}


TABLES = load_columns()
VIEWS = load_views()


# --------------------------------------------------------------------------
# SQL literals
# --------------------------------------------------------------------------

def sql_literals(text):
    """Yield (line_no, body) for quoted strings that look like SQL."""
    for m in STRING_RE.finditer(text):
        body = m.group(1) if m.group(1) is not None else m.group(2)
        if body is None:
            continue
        up = body.upper()
        if not any(k in up for k in ("SELECT ", "INSERT ", "UPDATE ", "DELETE ", "FROM ")):
            continue
        yield text.count("\n", 0, m.start()) + 1, body


def strip_comments(sql):
    sql = re.sub(r"/\*.*?\*/", " ", sql, flags=re.S)
    return re.sub(r"--[^\n]*", " ", sql)


def alias_map(sql):
    """alias -> table, for tables named in FROM/JOIN. Ambiguous aliases omitted."""
    found = {}

    def record(alias, table):
        table = table.lower()
        if alias in found and found[alias] != table:
            found[alias] = None          # ambiguous, skip it
        elif alias not in found:
            found[alias] = table

    for m in re.finditer(
        r"\b(?:FROM|JOIN)\s+`?(\w+)`?\s*(?:AS\s+)?(\w+)?", sql, re.I
    ):
        table, alias = m.group(1), m.group(2)
        if table.lower() in NOT_AN_ALIAS:
            continue
        # A bare keyword right after the table name is not an alias.
        if alias and alias.lower() in NOT_AN_ALIAS:
            alias = None
        record(alias or table, table)

    return {a: t for a, t in found.items() if t}


COLUMN_REF = re.compile(r"\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\.\s*`?([a-zA-Z_][a-zA-Z0-9_]*)`?")

# Tables named by a query. A table that does not exist is fatal 1146, and the
# column check above cannot see it when nothing is selected -- `SELECT COUNT(*)
# FROM notices` names no columns at all. The diagnostics self-check carried exactly
# that: a `notices` query for a table the schema has always called
# `announcements`, which reported one bold red failure and hid the twelve checks
# that were fine.
TABLE_REF = re.compile(
    r"(?<![:\w])(?:FROM|JOIN|INTO)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?", re.I
)
# UPDATE only counts when it opens the statement. Mid-string it is MySQL's
# `ON DUPLICATE KEY UPDATE assigned_user_id = ...`, which names a column.
UPDATE_REF = re.compile(r"^\s*UPDATE\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?", re.I)

# Read from an information_schema query, or interpolated into a quoted identifier.
# Either way the table is not a literal and cannot be checked here.
NOT_A_TABLE = {
    "select", "insert", "update", "delete", "from", "where", "values", "set",
    "dual", "information_schema", "and", "or", "not", "on", "using", "key",
    "duplicate", "table", "index", "unique", "primary", "order", "group", "by",
    "having", "limit", "offset", "union", "all", "as", "into", "exists", "in",
}


SQL_VERB = re.compile(r"\b(?:SELECT|INSERT|UPDATE|DELETE|REPLACE)\b", re.I)


def check_tables(body, where):
    """Every literal table named by the query must exist in sql/schema.sql."""
    sql = strip_comments(body)
    # Require a real statement verb first. The SQL-literal gate is deliberately
    # permissive (a fragment like " AND x >= :from" needs checking too), but that
    # also admits ordinary English -- 'Clear personal items from the shared
    # fridge' contains "from the", and was reported as a missing table.
    if not SQL_VERB.search(sql):
        return []

    problems = []
    seen = set()
    refs = [m.group(1) for m in TABLE_REF.finditer(sql)]
    refs += [m.group(1) for m in UPDATE_REF.finditer(sql)]
    for name in refs:
        table = name.lower()
        if table in NOT_A_TABLE or "$" in name:
            continue
        if table in TABLES or table in VIEWS or table in seen:
            continue
        seen.add(table)
        problems.append(f"{where}  table `{table}` does not exist "
                        f"({_closest(table)})")
    return problems


def _closest(name):
    """A likely intended name, so the finding is actionable rather than just true."""
    import difflib
    matches = difflib.get_close_matches(name, list(TABLES), n=1, cutoff=0.6)
    return f"did you mean `{matches[0]}`?" if matches else "no similar table in schema.sql"


def check_sql(body, where):
    """Return list of findings for one SQL literal."""
    sql = strip_comments(body)
    aliases = alias_map(sql)
    problems = check_tables(body, where)

    for m in COLUMN_REF.finditer(sql):
        alias, col = m.group(1).lower(), m.group(2).lower()
        if col == "*":
            continue
        if alias in NOT_AN_ALIAS:
            continue
        if alias not in aliases:
            continue                       # derived-table output alias, etc.
        cols = TABLES.get(aliases[alias])
        if cols is None or col in cols:
            continue
        problems.append(f"{where}  {aliases[alias]}.{col} does not exist")

    return problems


# --------------------------------------------------------------------------
# Database::insert('t', [...]) / Database::update('t', [...])
# --------------------------------------------------------------------------

WRITE_RE = re.compile(
    r"Database::(insert|update)\s*\(\s*'([^']+)'\s*,\s*\[(.*?)\]\s*(?:,|\))", re.S
)
KEY_RE = re.compile(r"'([a-zA-Z_][a-zA-Z0-9_]*)'\s*=>")


def check_writes(text, where):
    problems = []
    for m in WRITE_RE.finditer(text):
        table, payload = m.group(2).lower(), m.group(3)
        cols = TABLES.get(table)
        if cols is None:
            continue
        line = text.count("\n", 0, m.start()) + 1
        for key in KEY_RE.findall(payload):
            if key.lower() not in cols:
                problems.append(f"{where}:{line}  {table}.{key} does not exist")
    return problems


def strip_php_comments(text):
    """Blank PHP comments, preserving offsets so line numbers stay right.

    Needed because SQL is found by scanning quoted strings: a docblock that
    contains both an apostrophe and the word FROM looks exactly like a query,
    and one was reported as `table 'the' does not exist`.
    """
    out = []
    i, n = 0, len(text)
    while i < n:
        two = text[i:i + 2]
        if two == "/*":
            j = text.find("*/", i)
            j = n if j == -1 else j + 2
            out.append("".join("\n" if c == "\n" else " " for c in text[i:j]))
            i = j
        elif two == "//" or text[i] == "#":
            j = text.find("\n", i)
            j = n if j == -1 else j
            out.append(" " * (j - i))
            i = j
        elif text[i] in "'\"":
            quote = text[i]
            j = i + 1
            while j < n:
                if text[j] == "\\":
                    j += 2
                    continue
                if text[j] == quote:
                    break
                j += 1
            out.append(text[i:min(j + 1, n)])
            i = j + 1
        else:
            out.append(text[i])
            i += 1
    return "".join(out)


def main():
    findings = []
    scanned = 0

    for d in SRC_DIRS:
        base = ROOT / d
        if not base.exists():
            continue
        for path in sorted(base.rglob("*.php")):
            scanned += 1
            rel = path.relative_to(ROOT).as_posix()
            text = strip_php_comments(
                path.read_text(encoding="utf-8", errors="replace"))
            for line, body in sql_literals(text):
                findings += check_sql(body, f"{rel}:{line}")
            findings += check_writes(text, rel)

    print(f"schema tables : {len(TABLES)} (+{len(VIEWS)} views)")
    print(f"scanned       : {scanned} PHP files")

    if not findings:
        print("OK - every SQL table and column reference resolves "
              "against sql/schema.sql")
        return 0

    print(f"\n{len(findings)} unknown table/column reference(s):\n")
    for f in findings:
        print(f"  {f}")
    return 1


if __name__ == "__main__":
    sys.exit(main())