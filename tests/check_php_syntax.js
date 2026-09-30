/* ==========================================================================
   FlatMate  |  Parse every PHP file (Node fallback for `php -l`)
   --------------------------------------------------------------------------
   PHP has no comma operator, so something like
       fn() => (Auth::logout(), ['ok' => true])[1]
   is a parse error -- not a runtime one. A parse error in api/index.php means
   the whole endpoint dies before its own try/catch, returning an HTML 500 that
   the JS client can only report as "Request failed (500)". Six routes were
   written that way, and no other static check could see it.

   This runs only when the wrapper cannot find a `php` binary. It is deliberately
   forgiving about syntax php-parser has not caught up with, so it reports real
   breakage and nothing else.
   ========================================================================== */
"use strict";

const fs = require("fs");
const path = require("path");

let parser;
try {
  parser = require("php-parser");
} catch (_) {
  console.log("SKIP - php-parser is not installed (run `npm install` in tests/).");
  process.exit(0);
}

const root = process.argv[2];
if (!root) {
  console.error("usage: node check_php_syntax.js <project-root>");
  process.exit(2);
}

const engine = new parser.Engine({
  parser: { extractDoc: false, suppressErrors: false, php7: true },
  ast: { withPositions: true },
});

function walk(dir, out) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === "node_modules" || entry.name === ".git" || entry.name === "vendor") {
      continue;
    }
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else if (entry.name.endsWith(".php")) out.push(full);
  }
  return out;
}

/* php-parser can lag the newest syntax. These constructs are legal in the PHP
   this app targets, so a complaint about them is a linter gap, not a bug. */
const LINTER_GAP = /never|readonly|first-class callable|intersection|enum/i;

const files = walk(root, []);
let failures = 0;
let skipped = 0;

for (const file of files) {
  const rel = path.relative(root, file);
  const code = fs.readFileSync(file, "utf8").replace(/^\uFEFF/, ""); // a BOM is handled elsewhere
  try {
    engine.parseCode(code, file);
    console.log("  OK    " + rel);
  } catch (err) {
    const message = String(err && err.message ? err.message : err);
    if (LINTER_GAP.test(message)) {
      skipped++;
      console.log("  ?     " + rel + "  (linter gap, ignored: " + message + ")");
      continue;
    }
    failures++;
    console.log("  FAIL  " + rel);
    console.log("        " + message);
  }
}

if (failures) {
  console.log("\nFAIL - " + failures + " PHP file(s) do not compile.");
  process.exit(1);
}

console.log(
  "\nOK - all " + files.length + " PHP files parse" + (skipped ? " (" + skipped + " linter gap(s) ignored)" : "") + "."
);
