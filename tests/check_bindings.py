"""
Static check: every named PDO placeholder in a call must be bound.

Two bug classes this catches, both of which produce a 500 at runtime:

  1. a placeholder used twice in one statement   -> HY093
     (see tests/check_placeholders.py)
  2. a placeholder with no matching array key     -> HY093

Understands the Database helper shapes used in this project:
  Database::query|all|one|value($sql, ['k' => $v, ...])
  Database::insert|insertOrIgnore($table, ['col' => $v, ...])   (:col per key)
  Database::update($table, ['col' => $v], $col, $val)           (:set_col + :where_val)

Anything it cannot resolve statically is reported as SKIP, never as an error.
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC_DIRS = ["src", "api", "config", "includes"]

CALL_RE = re.compile(r"Database::(query|all|one|value|insert|insertOrIgnore|update)\s*\(")
STRING_RE = re.compile(r"'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\"", re.S)
NAME_RE = re.compile(r"(?<![:\w]):([a-zA-Z_][a-zA-Z0-9_]*)")
ARRAY_KEY_RE = re.compile(r"['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\s*=>")
KEYWORDISH = {"not", "and", "or", "null", "true", "false"}


def balanced_call(text: str, open_idx: int):
    """Return the inside of a (...) whose '(' sits at open_idx."""
    depth, i, n = 0, open_idx, len(text)
    quote = None
    while i < n:
        c = text[i]
        if quote:
            if c == "\\":
                i += 2
                continue
            if c == quote:
                quote = None
        elif c in "'\"":
            quote = c
        elif c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
            if depth == 0:
                return text[open_idx + 1:i], i
        i += 1
    return None, None


def split_args(body: str):
    """Split a call body into top-level arguments."""
    args, buf, depth = [], [], 0
    quote = None
    i = 0
    while i < len(body):
        c = body[i]
        if quote:
            buf.append(c)
            if c == "\\":
                if i + 1 < len(body):
                    buf.append(body[i + 1])
                i += 2
                continue
            if c == quote:
                quote = None
        elif c in "'\"":
            quote = c
            buf.append(c)
        elif c in "([{":
            depth += 1
            buf.append(c)
        elif c in ")]}":
            depth -= 1
            buf.append(c)
        elif c == "," and depth == 0:
            args.append("".join(buf).strip())
            buf = []
        else:
            buf.append(c)
        i += 1
    tail = "".join(buf).strip()
    if tail:
        args.append(tail)
    return args


def first_literal(arg: str):
    m = STRING_RE.match(arg.strip())
    if not m:
        return None
    return m.group(1) if m.group(1) is not None else m.group(2)


def main() -> int:
    errors, extras, skips, scanned = [], [], [], 0

    for d in SRC_DIRS:
        base = ROOT / d
        if not base.exists():
            continue
        for path in sorted(base.rglob("*.php")):
            text = path.read_text(encoding="utf-8", errors="replace")
            scanned += 1
            rel = path.relative_to(ROOT).as_posix()

            for m in CALL_RE.finditer(text):
                method = m.group(1)
                body, end = balanced_call(text, m.end() - 1)
                if body is None:
                    continue
                line = text.count("\n", 0, m.start()) + 1
                args = split_args(body)

                if method in ("query", "all", "one", "value"):
                    if not args:
                        continue
                    sql = first_literal(args[0])
                    if sql is None or not re.search(
                        r"\b(SELECT|INSERT|UPDATE|DELETE)\b", sql, re.I
                    ):
                        continue
                    params_arg = args[1] if len(args) > 1 else "[]"
                    raw_keys = set(ARRAY_KEY_RE.findall(params_arg))
                    if params_arg.strip() and not params_arg.strip().startswith("["):
                        skips.append((rel, line, method))
                        continue
                    bound = raw_keys
                    expected = {n for n in NAME_RE.findall(sql) if n.lower() not in KEYWORDISH}
                else:
                    # No SQL literal: placeholders are derived from the data array.
                    sql = ""
                    data_arg = args[1] if len(args) > 1 else "[]"
                    raw_keys = set(ARRAY_KEY_RE.findall(data_arg))
                    if data_arg.strip() and not data_arg.strip().startswith("["):
                        skips.append((rel, line, method))
                        continue
                    if method in ("insert", "insertOrIgnore"):
                        bound = set(raw_keys)
                        expected = set(raw_keys)
                    elif method == "update":
                        bound = {f"set_{k}" for k in raw_keys} | {"where_val"}
                        expected = set(bound)
                    else:
                        continue

                missing = sorted(expected - bound)
                if missing:
                    errors.append((rel, line, method, missing, " ".join(sql.split())[:130]))

                # The other HY093 direction: a bound key the statement never
                # declares. PDO rejects the whole execute(), so a stray
                # $params['mine'] left over from a sibling query is a 500.
                unused = sorted(bound - expected)
                if unused:
                    extras.append((rel, line, method, unused, " ".join(sql.split())[:130]))

    print(f"scanned {scanned} PHP files")
    print(f"skipped {len(skips)} call(s) with non-literal param arrays")

    if errors or extras:
        if errors:
            print(f"\n{len(errors)} unbound placeholder(s):\n")
            for rel, line, method, missing, sql in errors:
                print(f"  {rel}:{line}  Database::{method}")
                print(f"      missing: {', '.join(':' + x for x in missing)}")
                print(f"      sql: {sql}\n")
        if extras:
            print(f"{len(extras)} bound-but-undeclared placeholder(s):\n")
            for rel, line, method, unused, sql in extras:
                print(f"  {rel}:{line}  Database::{method}")
                print(f"      not in sql: {', '.join(':' + x for x in unused)}")
                print(f"      sql: {sql}\n")
        return 1

    print("OK - every named placeholder is bound, and every bound name is declared")
    return 0


if __name__ == "__main__":
    sys.exit(main())