"""Catch three MySQL restrictions that a plain parse happily accepts.

1. A subquery whose LIMIT/OFFSET refers to a column of the enclosing query.
   MySQL cannot resolve outer references in a subquery's LIMIT/OFFSET, so this
   only ever fails on the server. sql/seed.sql section 14 did exactly this and
   had to be rewritten as a numbered rotation pool.
2. INSERT INTO t ... SELECT ... FROM t, which MySQL rejects with error 1093
   ("You can't specify target table for update in FROM clause").
3. A joined derived table whose body is a top-level UNION ALL of aggregates. If
   a user can appear in more than one branch, the join multiplies their totals
   and results keyed by user id silently drop rows, so the ledger stops summing
   to zero. vw_balance_sheet did this with settlements and broke every balance
   read (HTTP 400 from DebtSimplifier). Collapse the union with an outer GROUP BY.

Comments are stripped first, so prose describing a bad pattern does not trip it.
"""
import re
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

ROOT = Path(__file__).resolve().parent.parent

# Statements that can lose rows. Comments are stripped before this runs, so
# prose about DROP TABLE does not trip it.
DESTRUCTIVE = re.compile(
    r"\b(?:DROP\s+(?:TABLE|DATABASE)\b"
    r"|TRUNCATE(?:\s+TABLE)?\b"
    r"|DELETE\s+FROM\b"
    r"|RENAME\s+TABLE\b"
    r"|ALTER\s+TABLE\s+\w+`?\s+(?:DROP|TRUNCATE)\b)",
    re.I,
)


def strip_comments(text):
    out, quote, i, n = [], None, 0, len(text)
    while i < n:
        ch = text[i]
        if quote:
            out.append(ch)
            if ch == quote:
                quote = None
            i += 1
            continue
        if ch in "'\"`":
            quote = ch
            out.append(ch)
            i += 1
            continue
        if text.startswith("--", i) and (i + 2 >= n or text[i + 2] in " \t\r\n"):
            nl = text.find("\n", i)
            if nl == -1:
                break
            out.append(" " * (nl - i))       # keep line numbers stable
            i = nl
            continue
        if text.startswith("/*", i):
            end = text.find("*/", i + 2)
            end = n if end == -1 else end + 2
            out.append(" " * (end - i))
            i = end
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def matching_paren(text, open_idx):
    """Index of the ')' matching the '(' at open_idx, or -1."""
    depth = 0
    for i in range(open_idx, len(text)):
        if text[i] == "(":
            depth += 1
        elif text[i] == ")":
            depth -= 1
            if depth == 0:
                return i
    return -1


def has_top_level_union_all(body):
    """True if a UNION ALL sits at depth 0 of body (its top-level query)."""
    depth = 0
    i, n = 0, len(body)
    while i < n:
        c = body[i]
        if c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
        elif depth == 0 and body[i:i + 9].upper() == "UNION ALL":
            return True
        i += 1
    return False


def line_of(text, index):
    return text[:index].count("\n") + 1


findings = 0

for name in ("sql/schema.sql", "sql/seed.sql"):
    raw = (ROOT / name).read_text(encoding="utf-8")
    text = strip_comments(raw)

    # (1) LIMIT/OFFSET inside a parenthesised subquery
    for m in re.finditer(r"\(([^()]*(?:\([^()]*\)[^()]*)*)\)", text):
        body = m.group(1)
        if not re.search(r"\bLIMIT\b", body, re.I):
            continue
        findings += 1
        print(f"  {name}:{line_of(text, m.start())}  subquery with LIMIT/OFFSET")
        print(f"      {body.strip()[:150]}")

    # (2) INSERT INTO t that also reads FROM t
    for m in re.finditer(
        r"INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\b(.*?);", text, re.I | re.S
    ):
        table, body = m.group(1), m.group(2)
        if not re.search(r"\bSELECT\b", body, re.I):
            continue
        targets = re.findall(r"\b(?:FROM|JOIN)\s+`?(\w+)`?", body, re.I)
        if table in targets:
            findings += 1
            print(f"  {name}:{line_of(text, m.start())}  INSERT INTO {table} "
                  f"also reads FROM {table} (MySQL error 1093)")

    # (3) JOIN ( SELECT ... UNION ALL SELECT ... ) alias ON ...
    for m in re.finditer(r"\bJOIN\s*\(", text, re.I):
        open_idx = text.index("(", m.start())
        close_idx = matching_paren(text, open_idx)
        if close_idx == -1:
            continue
        if not re.match(r"\s*`?\w+`?\s+ON\b", text[close_idx + 1:], re.I):
            continue                       # not a derived table joined via ON
        body = text[open_idx + 1:close_idx]
        if not has_top_level_union_all(body):
            continue
        if not re.search(r"\b(?:SUM|COUNT)\s*\(", body, re.I):
            continue
        findings += 1
        print(f"  {name}:{line_of(text, m.start())}  joined UNION ALL of "
              f"aggregates without an outer GROUP BY (may multiply rows)")

    # (4) sql/patch.sql promises to be additive. Hold it to that: it exists so a
    #     fix can be applied without re-importing schema.sql, which DROPs every
    #     table. A destructive statement in it would defeat the entire point.
    if name == "sql/patch.sql":
        for m in re.finditer(DESTRUCTIVE, text, re.I):
            findings += 1
            print(f"  {name}:{line_of(text, m.start())}  destructive statement "
                  f"({m.group(0).strip().upper()}) - patch.sql must never "
                  f"delete data")

if findings:
    print(f"\nFAIL - {findings} risky construct(s).")
    sys.exit(1)
print("OK - no LIMIT/OFFSET subqueries, no INSERT..SELECT on its own target, "
      "no joined UNION ALL of aggregates, and patch.sql is additive only.")