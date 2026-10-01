"""
Scan PHP sources for SQL strings that reuse a named PDO placeholder.

Native prepared statements (ATTR_EMULATE_PREPARES = false) reject a named
placeholder used twice in one statement with HY093, so each distinct name must
appear exactly once per statement.
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC_DIRS = ["src", "api", "config", "includes"]

STRING_RE = re.compile(r"'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\"", re.S)
NAME_RE = re.compile(r"(?<![:\w]):([a-zA-Z_][a-zA-Z0-9_]*)")

# PHP casts / words that look like SQL but are not placeholders
KEYWORDISH = {"not", "and", "or", "null", "true", "false"}


def sql_strings(text):
    """Yield (line_no, literal) for quoted strings that could carry SQL.

    Deliberately wider than "looks like a full statement". A query is often
    assembled at runtime from small clause fragments, so a fragment that is never
    a valid statement on its own is exactly where a reused placeholder hides.
    """
    for m in STRING_RE.finditer(text):
        body = m.group(1) if m.group(1) is not None else m.group(2)
        if body is None:
            continue
        line = text.count("\n", 0, m.start()) + 1
        yield line, body


# Tokens that make a literal part of a statement rather than, say, a URL or a
# CSS selector. Needed so the widened scan does not flag `:id` twice in prose.
SQLISH = ("SELECT ", "INSERT ", "UPDATE ", "DELETE ", "FROM ", " WHERE ",
          " LIKE ", " AND ", " OR ", " IN ", "=", "(", ",")


def main() -> int:
    problems = []
    scanned = 0

    for d in SRC_DIRS:
        base = ROOT / d
        if not base.exists():
            continue
        for path in sorted(base.rglob("*.php")):
            text = path.read_text(encoding="utf-8", errors="replace")
            scanned += 1
            for line, body in sql_strings(text):
                names = [n for n in NAME_RE.findall(body) if n.lower() not in KEYWORDISH]
                seen, dupes = set(), set()
                for n in names:
                    if n in seen:
                        dupes.add(n)
                    seen.add(n)
                if not dupes:
                    continue
                up = body.upper()
                if not any(k in up for k in SQLISH):
                    continue
                problems.append((path.relative_to(ROOT).as_posix(), line, sorted(dupes), body))

    print(f"scanned {scanned} PHP files")
    if not problems:
        print("OK - no duplicated named placeholders found")
        return 0

    print(f"\n{len(problems)} statement(s) reuse a named placeholder:\n")
    for rel, line, dupes, body in problems:
        one = " ".join(body.split())
        print(f"  {rel}:{line}  -> {', '.join(':' + d for d in dupes)}")
        print(f"      {one[:150]}")
    return 1


def self_test() -> int:
    """Prove the scan still flags a reused placeholder.

    Worth having because the original scan was narrower and shipped a real bug
    past it: ExpenseService's search filter reused :q inside a `$where[]`
    fragment, which is not a statement on its own and so never matched the old
    "looks like SQL" gate. A checker nobody can prove still bites is a checker
    that quietly stops working.
    """
    cases = [
        # (label, source, expected duplicate names)
        ("reused name in a clause fragment",
         "$where[] = '(e.title LIKE :q OR e.description LIKE :q)';", ["q"]),
        ("split names are clean",
         "$where[] = '(e.title LIKE :q1 OR e.description LIKE :q2)';", []),
        ("repeated name is not SQL and must be ignored",
         "$u = '/user/:id/profile/:id';", []),
    ]
    problems = []
    for label, src, expected in cases:
        found = set()
        for _line, body in sql_strings(src):
            names = [n for n in NAME_RE.findall(body) if n.lower() not in KEYWORDISH]
            seen = set()
            for n in names:
                if n in seen:
                    found.add(n)
                seen.add(n)
            up = body.upper()
            if not any(k in up for k in SQLISH):
                found = found - set(names)
        got = sorted(found)
        if got != expected:
            problems.append(f"{label}: expected {expected}, got {got}")

    if problems:
        print("\nFAIL - placeholder self-test:\n")
        for p in problems:
            print(f"  {p}")
        return 1
    print(f"OK - placeholder self-test: {len(cases)} cases")
    return 0


if __name__ == "__main__":
    if "--self-test" in sys.argv:
        sys.exit(self_test())
    sys.exit(main())