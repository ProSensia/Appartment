"""Static column/value arity check for INSERT ... VALUES and INSERT ... SELECT.

MySQL error 1136 ("Column count doesn't match value count at row 1") is the
failure this prevents: sql/seed.sql section 11 listed 13 columns but supplied
12 values, which aborted the whole seed.

The parser must stop at the statement's terminating semicolon at nesting depth
zero. Without that, the parens of every following statement are counted as
extra value rows.
"""
import re
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):        # seed data contains non-cp1252 text
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

ROOT = Path(__file__).resolve().parent.parent


def scan_values(text, start):
    """Return (rows, end_index). rows is a list of value-lists.

    Walks forward from `start`, tracking paren depth, quote state and comments.
    Collects each top-level (...) group, and stops at a ';' at depth 0.
    """
    rows, buf, depth, quote = [], [], 0, None
    i = start
    n = len(text)

    while i < n:
        ch = text[i]

        # Line and block comments must be skipped or their parens/commas count.
        if quote is None and text.startswith("--", i) and (i + 2 >= n or text[i + 2] in " \t\r\n"):
            nl = text.find("\n", i)
            i = n if nl == -1 else nl + 1
            continue
        if quote is None and text.startswith("/*", i):
            end = text.find("*/", i + 2)
            i = n if end == -1 else end + 2
            continue

        if quote:
            buf.append(ch)
            if ch == "\\" and i + 1 < n:      # escaped char inside a string
                buf.append(text[i + 1])
                i += 2
                continue
            if ch == quote:
                # Doubled quote is an escaped quote, not a terminator.
                if i + 1 < n and text[i + 1] == quote:
                    buf.append(text[i + 1])
                    i += 2
                    continue
                quote = None
            i += 1
            continue

        if ch in "'\"`":
            quote = ch
            buf.append(ch)
            i += 1
            continue

        if ch == "(":
            if depth == 0:
                buf = []
            else:
                buf.append(ch)   # keep nesting visible to split_top_level
            depth += 1
            i += 1
            continue
        if ch == ")":
            depth -= 1
            if depth == 0:
                rows.append(split_top_level("".join(buf)))
            else:
                buf.append(ch)
            i += 1
            continue
        if ch == ";" and depth == 0:
            return rows, i

        if depth > 0:
            buf.append(ch)
        i += 1

    return rows, n


def split_top_level(text):
    parts, buf, depth, quote = [], [], 0, None
    for ch in text:
        if quote:
            buf.append(ch)
            if ch == quote:
                quote = None
            continue
        if ch in "'\"`":
            quote = ch
            buf.append(ch)
            continue
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
        if ch == "," and depth == 0:
            parts.append("".join(buf).strip())
            buf = []
        else:
            buf.append(ch)
    tail = "".join(buf).strip()
    if tail:
        parts.append(tail)
    return [p for p in parts if p != ""]


def top_level_select_items(text):
    """Split a SELECT list on top-level commas (no FROM in the slice)."""
    items, buf, depth, quote = [], [], 0, None
    i = 0
    while i < len(text):
        ch = text[i]
        if quote:
            buf.append(ch)
            if ch == "\\" and i + 1 < len(text):
                buf.append(text[i + 1])
                i += 2
                continue
            if ch == quote:
                quote = None
            i += 1
            continue
        if ch in "'\"`":
            quote = ch
            buf.append(ch)
            i += 1
            continue
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
        if depth == 0 and text[i:i + 4].upper() == "FROM":
            break
        if ch == "," and depth == 0:
            items.append("".join(buf).strip())
            buf = []
        else:
            buf.append(ch)
        i += 1
    tail = "".join(buf).strip()          # last item before FROM
    if tail:
        items.append(tail)
    return [x for x in items if x]


failures = 0

for name in ("sql/schema.sql", "sql/seed.sql"):
    path = ROOT / name
    text = path.read_text(encoding="utf-8")

    for m in re.finditer(
        r"INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*\(([^)]*)\)\s*VALUES",
        text,
        re.I,
    ):
        table = m.group(1)
        cols = [c.strip().strip("`") for c in m.group(2).split(",") if c.strip()]
        line_no = text[: m.start()].count("\n") + 1

        rows, _ = scan_values(text, m.end())
        if not rows:
            continue

        for i, vals in enumerate(rows):
            if len(vals) != len(cols):
                failures += 1
                where = "first row" if i == 0 else f"row {i + 1}"
                print(f"  {name}:{line_no}  {table} ({where}) "
                      f"{len(vals)} value(s) vs {len(cols)} column(s)")
                print(f"      columns: {', '.join(cols)}")
                print(f"      values : {', '.join(v[:28] for v in vals)}")

    for m in re.finditer(
        r"INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*\(([^)]*)\)\s*\n?\s*SELECT\s+",
        text,
        re.I,
    ):
        table = m.group(1)
        cols = [c.strip().strip("`") for c in m.group(2).split(",") if c.strip()]
        line_no = text[: m.start()].count("\n") + 1

        # Take up to the statement's terminating semicolon.
        _, end = scan_values(text, m.end())
        body = text[m.end(): end]
        items = top_level_select_items(body)

        if items and len(items) != len(cols):
            failures += 1
            print(f"  {name}:{line_no}  {table} (SELECT) "
                  f"{len(items)} value(s) vs {len(cols)} column(s)")
            print(f"      columns: {', '.join(cols)}")
            print(f"      values : {', '.join(v[:28] for v in items)}")

if failures:
    print(f"\nFAIL - {failures} arity mismatch(es).")
    sys.exit(1)
print("OK - every INSERT supplies exactly as many values as columns.")