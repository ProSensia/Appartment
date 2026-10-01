"""Catch a call to a `: void` function whose return value is then used.

`Diag::clear()` was declared `: void` and the route read it as a boolean:

    'diag.clear' => ['POST', 'auth', fn() => (Diag::clear() ? [...] : [...])],

PHP raises "Cannot use result of void function" for this -- at runtime, on that
one code path. `php -l` passes, because it is not a syntax error; the parser-based
syntax check passes too. The Clear button simply 500s, and only after an admin
has already found it.

So the void-ness has to come from reading the declarations, and the use from
reading the call sites. Any function declared `: void` may not have its result
consumed. Statements (`foo();`, `fn() => foo()`, `if (foo())`) are fine and are
the overwhelming majority of calls; only genuine expression use is reported, so
this stays quiet unless it has something real to say.
"""
import re
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

ROOT = Path(__file__).resolve().parent.parent

# `if`, `foreach`, ... and friends: parens that are not calls.
KEYWORDS = {
    "if", "elseif", "else", "for", "foreach", "while", "do", "switch", "match",
    "catch", "return", "function", "fn", "new", "clone", "use", "echo", "print",
    "unset", "isset", "empty", "list", "array", "exit", "die", "and", "or", "xor",
    "instanceof", "include", "include_once", "require", "require_once", "yield",
    "static", "throw", "global", "declare", "namespace", "insteadof", "endif",
    "int", "float", "string", "bool", "array", "object", "mixed", "void", "never",
}

# After the closing paren, these mean the value is being used.
CONSUMED = set("?.=")
CONSUMED_WORDS = ("??", "&&", "||")


def blank(text):
    """Replace comments and string bodies with spaces, preserving offsets."""
    out = list(text)
    i, n = 0, len(text)
    while i < n:
        c = text[i]
        if c == "/" and i + 1 < n and text[i + 1] == "/":
            j = text.find("\n", i)
            j = n if j == -1 else j
            for k in range(i, j):
                out[k] = " "
            i = j
        elif c == "#":
            j = text.find("\n", i)
            j = n if j == -1 else j
            for k in range(i, j):
                out[k] = " "
            i = j
        elif c == "/" and i + 1 < n and text[i + 1] == "*":
            j = text.find("*/", i)
            j = n if j == -1 else j + 2
            for k in range(i, j):
                if out[k] != "\n":
                    out[k] = " "
            i = j
        elif c in "'\"":
            quote = c
            i += 1
            while i < n:
                if text[i] == "\\":
                    i += 2
                    continue
                if text[i] == quote:
                    break
                out[i] = " "
                i += 1
            i += 1
        else:
            i += 1
    return "".join(out)


def matching_paren(text, open_idx):
    depth = 0
    i, n = open_idx, len(text)
    while i < n:
        if text[i] == "(":
            depth += 1
        elif text[i] == ")":
            depth -= 1
            if depth == 0:
                return i
        i += 1
    return -1


def next_meaningful(text, idx):
    while idx < len(text) and text[idx] in " \t\r\n":
        idx += 1
    return idx


files = sorted(list(ROOT.glob("src/*.php")) + list(ROOT.glob("*.php"))
               + list(ROOT.glob("api/*.php")) + list(ROOT.glob("includes/*.php")))

# ---- 1. which functions are declared void --------------------------------
voids = {}
decl = re.compile(
    r"function\s+([A-Za-z_]\w*)\s*\([^;{]*\)\s*:\s*\?*\s*void\b", re.S)

for f in files:
    src = blank(f.read_text(encoding="utf-8", errors="replace"))
    for m in decl.finditer(src):
        voids[m.group(1)] = f"{f.relative_to(ROOT)}:{src[:m.start()].count(chr(10)) + 1}"

if not voids:
    print("FAIL - no `: void` declarations found; the scan itself is broken")
    sys.exit(1)

# ---- 2. is any of their results used -------------------------------------
problems = []
call = re.compile(r"(?<![\$>\w])([A-Za-z_]\w*)\s*\(")

for f in files:
    src = blank(f.read_text(encoding="utf-8", errors="replace"))
    for m in call.finditer(src):
        name = m.group(1)
        if name not in voids or name in KEYWORDS:
            continue
        close = matching_paren(src, m.end() - 1)
        if close < 0:
            continue
        after = next_meaningful(src, close + 1)
        if after >= len(src):
            continue
        ch = src[after]
        if ch not in CONSUMED:
            continue
        # '=' only counts as an assignment, not as ==, =>, or !==
        if ch == "=":
            nxt = src[after + 1] if after + 1 < len(src) else ""
            if nxt == "=" or nxt == ">":
                continue
        line = src[:m.start()].count("\n") + 1
        problems.append(
            f"{f.relative_to(ROOT)}:{line}  {name}() is `: void` "
            f"(declared {voids[name]}) but its result is used")

if problems:
    print(f"\nFAIL - {len(problems)} void-return misuse(es):\n")
    for p in problems:
        print(f"  {p}")
    sys.exit(1)

print(f"OK - {len(voids)} `: void` functions declared, "
      f"and no call site uses their result")