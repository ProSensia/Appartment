"""Catch two MySQL restrictions that a plain parse happily accepts.

1. A subquery whose LIMIT/OFFSET refers to a column of the enclosing query.
   MySQL cannot resolve outer references in a subquery's LIMIT/OFFSET, so this
   only ever fails on the server. sql/seed.sql section 14 did exactly this and
   had to be rewritten as a numbered rotation pool.
2. INSERT INTO t ... SELECT ... FROM t, which MySQL rejects with error 1093
   ("You can't specify target table for update in FROM clause").

Comments are stripped first, so prose describing a bad pattern does not trip it.
"""
import re
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

ROOT = Path(__file__).resolve().parent.parent


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

if findings:
    print(f"\nFAIL - {findings} risky construct(s).")
    sys.exit(1)
print("OK - no LIMIT/OFFSET subqueries and no INSERT..SELECT on its own target.")