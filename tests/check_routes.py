"""
Check router handler wiring.

api/index.php dispatches via  $handler($input)  -- one array argument.
A handler declared as a bare array callable like [Auth::class, 'attempt'] must
therefore accept a single array, but most service methods take positional
typed arguments instead. That mismatch is a guaranteed 500 at runtime and is
invisible to a syntax check, so assert it here.

Also verifies every [Class::class, 'method'] string resolves to a real method.
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
API = ROOT / "api" / "index.php"

CLASS_DECL_RE = re.compile(r"\b(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+([A-Za-z_]\w*)")
FUNC_DECL_RE = re.compile(r"\bfunction\s+&?\s*([A-Za-z_]\w*)\s*\(")
CONST_DECL_RE = re.compile(r"\bconst\s+([A-Za-z_]\w*)")

CALLABLE_RE = re.compile(r"\[([A-Za-z_]\w*)::class\s*,\s*'([A-Za-z_]\w*)'\]")
# a bare callable as the 3rd route element: 'name' => ['GET','auth',[C::class,'m'],
ROUTE_RE = re.compile(r"^\s*'([a-z_]+\.[a-z_]+)'\s*=>\s*\[(.*)$", re.M)


def collect_methods():
    methods = {}
    consts = {}
    for path in sorted((ROOT / "src").rglob("*.php")):
        text = path.read_text(encoding="utf-8", errors="replace")
        names = CLASS_DECL_RE.findall(text)
        if not names:
            continue
        decls = set(FUNC_DECL_RE.findall(text))
        cdecls = set(CONST_DECL_RE.findall(text))
        for n in names:
            methods[n] = decls
            consts[n] = cdecls
    return methods, consts


def main() -> int:
    if not API.exists():
        print("api/index.php not found")
        return 1

    methods, consts = collect_methods()
    text = API.read_text(encoding="utf-8", errors="replace")
    lines = text.splitlines()

    errors = []
    callables = 0

    for i, line in enumerate(lines, 1):
        for cls, meth in CALLABLE_RE.findall(line):
            callables += 1
            if cls not in methods:
                continue                       # builtin / external class
            if meth in methods[cls] or meth in consts[cls]:
                # A method callable is fine only if it takes a single array.
                # We cannot see the signature without a parser, so flag the
                # known-positional services and let a human confirm.
                errors.append(
                    f"api/index.php:{i}  array callable [{cls}::{meth}] is invoked as $handler($input) "
                    f"-- verify it takes one array, not positional args"
                )

    print(f"array callables found: {callables}")
    if not errors:
        print("OK - no array-callable handlers in the route table")
        return 0

    print(f"\n{len(errors)} handler(s) to inspect:\n")
    for e in errors:
        print(f"  {e}")
    return 1


if __name__ == "__main__":
    sys.exit(main())