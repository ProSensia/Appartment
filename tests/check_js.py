#!/usr/bin/env python
"""
Parse every front-end JavaScript file.

    python tests/check_js.py

There is no JS linter or test framework in this project, so `node --check`
is the only guard against a syntax error shipping to the browser. It earned
its place immediately: it caught a duplicate `const off` declaration in
residents.js that every other check passed straight over.
"""
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
JS_DIR = ROOT / "assets" / "js"

node = shutil.which("node")
if not node:
    print("SKIP - node is not on PATH, so JavaScript cannot be parsed.")
    print("       (the rest of the suite still applies)")
    sys.exit(0)

files = sorted(JS_DIR.glob("*.js"))
if not files:
    print("FAIL - no JavaScript files found in assets/js/")
    sys.exit(1)

failures = []
for path in files:
    result = subprocess.run(
        [node, "--check", str(path)],
        capture_output=True,
        text=True,
    )
    if result.returncode == 0:
        print(f"  OK    {path.relative_to(ROOT)}")
    else:
        failures.append(path.name)
        # node reports "file:line  <caret line>" plus a SyntaxError; keep the
        # first lines so the caret is still visible.
        detail = "\n".join(result.stderr.strip().splitlines()[:6])
        print(f"  FAIL  {path.relative_to(ROOT)}")
        print(detail)

if failures:
    print(f"\nFAIL - {len(failures)} file(s) did not parse: {', '.join(failures)}")
    sys.exit(1)

print(f"\nOK - all {len(files)} JavaScript files parse.")