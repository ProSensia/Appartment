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
    """Yield (line_no, literal) for quoted strings that look like SQL."""
    for m in STRING_RE.finditer(text):
        body = m.group(1) if m.group(1) is not None else m.group(2)
        if body is None:
            continue
        up = body.upper()
        if not any(k in up for k in ("SELECT ", "INSERT ", "UPDATE ", "DELETE ", "FROM ", " WHERE ")):
            continue
        line = text.count("\n", 0, m.start()) + 1
        yield line, body


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
                if dupes:
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


if __name__ == "__main__":
    sys.exit(main())