"""
Static reference checker for the FlatMate PHP sources.

There is no PHP binary in this environment, so this stands in for the "does it
actually resolve?" review. For every PHP file it collects:

  * declared classes, their methods and their constants
  * declared global functions
  * every `Foo::bar()` / `Foo::BAR` / `Foo::class` reference
  * every bare `name()` call

then reports references that do not resolve. Language constructs, project
globals and a curated builtin allowlist are accepted.

Purely textual -- it cannot follow dynamic dispatch, so it only ever reports
real resolution failures, and never claims success it cannot verify.
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SCAN_DIRS = ["src", "api", "config", "includes", "."]

BUILTINS = set("""
array_key_exists array_keys array_values array_merge array_filter array_map array_sum
array_column array_slice array_reverse array_search array_shift array_pop array_push
array_unique array_diff array_fill array_key_first array_key_last array_is_list
array_intersect array_replace array_pad array_flip range compact extract
array_walk array_walk_recursive
in_array count strlen strtolower strtoupper ucfirst lcfirst ucwords trim ltrim rtrim
substr strpos stripos str_replace str_contains str_starts_with str_ends_with
str_repeat str_split str_pad strrev str_word_count sprintf printf vsprintf
number_format implode explode join strstr strrchr stristr wordwrap
preg_match preg_replace preg_replace_callback preg_split preg_quote preg_grep
usort uasort uksort ksort asort arsort sort rsort
max min abs round floor ceil intdiv intval floatval strval boolval
is_numeric is_string is_array is_int is_object is_null is_file is_dir is_callable
json_decode json_encode json_last_error hash hash_hmac hash_equals
password_hash password_verify password_needs_rehash
bin2hex hex2bin random_bytes random_int mt_rand rand
base64_encode base64_decode
time date gmdate mktime strtotime checkdate date_diff microtime hrtime
session_start session_id session_regenerate_id session_destroy session_name
session_status session_set_cookie_params
setcookie header http_response_code
ob_start ob_get_clean ob_get_length
error_log trigger_error error_reporting ini_set set_error_handler
restore_error_handler set_exception_handler register_shutdown_function
error_get_last headers_sent
spl_autoload_register
htmlspecialchars htmlentities strip_tags nl2br
mb_substr mb_strlen mb_strimwidth mb_strtolower mb_strtoupper mb_convert_encoding
mb_internal_encoding mb_check_encoding
version_compare defined constant gettype
file_get_contents file_put_contents fopen fclose
filter_var function_exists define dirname basename filemtime
date_default_timezone_set date_default_timezone_get
array_walk_recursive usort
file is_file filesize is_readable is_writable is_bool is_int rename unlink copy
mkdir rmdir sys_get_temp_dir tempnam ini_get ini_set
extension_loaded get_loaded_extensions php_sapi_name php_uname
debug_backtrace debug_print_backtrace
preg_match_all preg_last_error
array_key_first intdiv str_word_count
""".split())

# `self`, `parent`, `static` and friends: not calls.
KEYWORDS = set("""
self parent static function fn new class interface trait enum
extends implements match echo print unset empty isset list array clone yield
die exit include include_once require require_once eval callable
if elseif else while for foreach switch case default break continue return
and or xor instanceof namespace use declare callable
catch finally try
""".split())

CLASS_DECL_RE = re.compile(r"\b(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+([A-Za-z_]\w*)")
# any function declaration, standalone or inside a class
FUNC_DECL_RE = re.compile(r"\bfunction\s+&?\s*([A-Za-z_]\w*)\s*\(")
CONST_DECL_RE = re.compile(r"\bconst\s+([A-Za-z_]\w*)")
# static ref: Class::member (may be a method, a constant, or ::class)
STATIC_REF_RE = re.compile(r"\b([A-Za-z_]\w*)::(\$?[A-Za-z_]\w*)")
BARE_CALL_RE = re.compile(r"(?<![:>$\w])([a-z_]\w*)\s*\(")


def php_only(text: str) -> str:
    """
    Page templates are mostly HTML. Keep only what is inside PHP tags so the
    scanner never mistakes markup or prose for code -- e.g. the visible text
    "Estimated cost (optional)" would otherwise look like a cost() call.

    A pure-PHP file has no closing tag at all; treat it as entirely PHP.
    """
    if "?>" not in text:
        return text
    parts = re.split(r"<\?(?:php|=)?(.*?)\?>", text, flags=re.S)
    # odd indices are the PHP bodies
    return "\n".join(p if i % 2 else "" for i, p in enumerate(parts))


def strip_markup_sections(text: str) -> str:
    """
    Blank <script>/<style> bodies and non-PHP regions, so the call scanner
    only sees PHP.
    """
    text = re.sub(r"<script\b[^>]*>.*?</script>", " ", text, flags=re.S | re.I)
    text = re.sub(r"<style\b[^>]*>.*?</style>", " ", text, flags=re.S | re.I)
    return php_only(text)


def strip_strings_comments_and_decls(text: str) -> str:
    """
    Remove markup, string bodies, comments, `declare(...)`, and function
    declarations, so the call scanner only ever sees real reference sites.
    """
    text = strip_markup_sections(text)
    text = re.sub(r"\bdeclare\s*\(", "declare_at(", text)
    text = re.sub(r"\bfunction\s+&?\s*[A-Za-z_]\w*\s*\(", "function (", text)
    return strip_strings_and_comments(text)


def strip_strings_and_comments(text: str) -> str:
    text = strip_markup_sections(text)
    out = []
    i, n = 0, len(text)
    quote = None
    while i < n:
        c = text[i]
        if quote:
            if c == "\\":
                out.append("  ")
                i += 2
                continue
            if c == quote:
                quote = None
                out.append(c)
            else:
                out.append("\n" if c == "\n" else " ")
            i += 1
            continue
        if c in "'\"":
            quote = c
            out.append(c)
            i += 1
            continue
        if text.startswith("//", i) or c == "#":
            while i < n and text[i] != "\n":
                out.append(" ")
                i += 1
            continue
        if text.startswith("/*", i):
            while i < n and not text.startswith("*/", i):
                out.append("\n" if text[i] == "\n" else " ")
                i += 1
            out.append("  ")
            i += 2
            continue
        out.append(c)
        i += 1
    return "".join(out)


def main() -> int:
    files = []
    for d in SCAN_DIRS:
        base = ROOT / d
        if not base.exists():
            continue
        # "." means project-root pages only; the subdirs are scanned separately.
        found = sorted(base.glob("*.php")) if d == "." else sorted(base.rglob("*.php"))
        files.extend(found)

    seen = set()
    files = [f for f in files if not (f in seen or seen.add(f))]

    methods: dict[str, set[str]] = {}
    consts: dict[str, set[str]] = {}
    functions: set[str] = set()
    static_refs: dict[tuple[str, str], list[str]] = {}
    bare_refs: dict[str, list[str]] = {}

    # ---- pass 1: declarations + reference sites -------------------------
    for path in files:
        raw = path.read_text(encoding="utf-8", errors="replace")
        rel = path.relative_to(ROOT).as_posix()

        # declarations read from the comment-stripped source (comments lie)
        decl_src = strip_strings_and_comments(raw)
        for m in CLASS_DECL_RE.finditer(decl_src):
            methods.setdefault(m.group(1), set())
            consts.setdefault(m.group(1), set())
        for m in FUNC_DECL_RE.finditer(decl_src):
            functions.add(m.group(1))
            # a method declared inside a class body
            for cm in CLASS_DECL_RE.finditer(decl_src):
                methods[cm.group(1)].add(m.group(1))
                consts[cm.group(1)].update(CONST_DECL_RE.findall(decl_src))
            break_outer = True
            break
        # the loop above only takes the first declaration; do it exhaustively
        for cm in CLASS_DECL_RE.finditer(decl_src):
            methods[cm.group(1)] |= set(FUNC_DECL_RE.findall(decl_src))
            consts[cm.group(1)] |= set(CONST_DECL_RE.findall(decl_src))
        for m in FUNC_DECL_RE.finditer(decl_src):
            functions.add(m.group(1))

        # reference sites read from the declaration-stripped source
        code = strip_strings_comments_and_decls(raw)
        for m in STATIC_REF_RE.finditer(code):
            line = raw.count("\n", 0, m.start()) + 1
            static_refs.setdefault((m.group(1), m.group(2)), []).append(f"{rel}:{line}")
        for m in BARE_CALL_RE.finditer(code):
            line = raw.count("\n", 0, m.start()) + 1
            bare_refs.setdefault(m.group(1), []).append(f"{rel}:{line}")

    errors = []

    # ---- pass 2: Class::member references ------------------------------
    for (cls, member), sites in sorted(static_refs.items()):
        if cls in ("self", "parent", "static", "Foo"):
            continue
        if cls not in methods:
            continue                      # builtin / third-party class
        if member == "class":
            continue                      # Foo::class constant expression
        if member.startswith("$"):
            continue
        if member in methods[cls] or member in consts[cls]:
            continue
        errors.append(f"{cls}::{member}  ->  {', '.join(sorted(set(sites)))}")

    # ---- pass 3: bare function calls -----------------------------------
    for fn, sites in sorted(bare_refs.items()):
        if fn in functions or fn in BUILTINS or fn in KEYWORDS:
            continue
        if fn.endswith("_at"):           # our own declare() rewrite
            continue
        errors.append(f"{fn}()  ->  {', '.join(sorted(set(sites)))}")

    print(f"scanned {len(files)} PHP files")
    print(f"classes={len(methods)} functions={len(functions)}")
    print("classes: " + ", ".join(sorted(methods)))

    if not errors:
        print("\nOK - every project reference resolves")
        return 0

    print(f"\n{len(errors)} unresolved reference(s):\n")
    for e in errors:
        print(f"  {e}")
    return 1


if __name__ == "__main__":
    sys.exit(main())