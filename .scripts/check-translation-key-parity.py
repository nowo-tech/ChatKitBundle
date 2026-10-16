#!/usr/bin/env python3
"""Fail if translation YAML files do not share the same nested key set (REQ-I18N-002)."""

from __future__ import annotations

import re
import sys
from pathlib import Path

REQUIRED_LOCALES = ("en", "es", "it", "fr", "pt", "de", "nl")
DOMAIN = "NowoChatKitBundle"  # REQ-I18N-003
TRANS_DIR = Path(__file__).resolve().parents[1] / "src" / "Resources" / "translations"


def flatten(path: Path) -> set[str]:
    keys: set[str] = set()
    stack: list[tuple[int, str]] = []
    for raw in path.read_text(encoding="utf-8").splitlines():
        if not raw.strip() or raw.lstrip().startswith("#"):
            continue
        indent = len(raw) - len(raw.lstrip(" "))
        key = raw.split(":", 1)[0].strip().strip("\"'")
        if not key:
            continue
        while stack and stack[-1][0] >= indent:
            stack.pop()
        stack.append((indent, key))
        keys.add(".".join(item[1] for item in stack))
    return keys


# A literal key piped to |trans, with or without arguments: 'key'|trans, 'key'|trans(...).
TRANS_CALL = re.compile(r"""(['"])([A-Za-z0-9_.\-]+)\1\s*\|\s*trans\b""")
DEFAULT_DOMAIN = re.compile(r"""\{%-?\s*trans_default_domain\s+(['"])([^'"]+)\1""")


def split_args(text: str, start: int) -> list[str] | None:
    """Return the top-level comma-separated arguments of the call opening at text[start] == '('."""
    depth = 0
    quote = ""
    args: list[str] = []
    current = ""
    for i in range(start, len(text)):
        char = text[i]
        if quote:
            current += char
            if char == quote and text[i - 1] != "\\":
                quote = ""
            continue
        if char in "'\"":
            quote = char
            current += char
        elif char in "([{":
            depth += 1
            if depth > 1:
                current += char
        elif char in ")]}":
            depth -= 1
            if depth == 0:
                if current.strip():
                    args.append(current.strip())
                return args
            current += char
        elif char == "," and depth == 1:
            args.append(current.strip())
            current = ""
        else:
            current += char
    return None


def twig_keys(source: str) -> set[str]:
    """Literal keys translated in the bundle domain: explicit 2nd/3rd-arg form or trans_default_domain."""
    default = DEFAULT_DOMAIN.search(source)
    file_domain = default.group(2) if default else None
    keys: set[str] = set()
    for match in TRANS_CALL.finditer(source):
        rest = match.end()
        while rest < len(source) and source[rest] in " \t":
            rest += 1
        domain = file_domain
        if rest < len(source) and source[rest] == "(":
            args = split_args(source, rest) or []
            if len(args) >= 2:
                literal = re.fullmatch(r"""(['"])([^'"]+)\1""", args[1])
                # A non-literal domain (variable) cannot be resolved statically: skip it.
                domain = literal.group(2) if literal else None
        if domain == DOMAIN:
            keys.add(match.group(2))
    return keys


def main() -> int:
    if not TRANS_DIR.is_dir():
        # Skeleton phase: the bundle ships no catalogues yet (REQ-MAKE-004 applies once they exist).
        print(f"No translations yet ({TRANS_DIR}); key parity check skipped.")
        return 0

    files = {loc: TRANS_DIR / f"{DOMAIN}.{loc}.yaml" for loc in REQUIRED_LOCALES}
    missing = [str(path) for path in files.values() if not path.is_file()]
    if missing:
        print("ERROR: missing locale files:", file=sys.stderr)
        print("\n".join(missing), file=sys.stderr)
        return 1

    reference = flatten(files["en"])
    failed = False
    for loc, path in files.items():
        keys = flatten(path)
        extra = sorted(keys - reference)
        absent = sorted(reference - keys)
        if extra or absent:
            failed = True
            print(f"ERROR: key mismatch in {path.name} vs en", file=sys.stderr)
            for key in absent:
                print(f"  missing: {key}", file=sys.stderr)
            for key in extra:
                print(f"  extra: {key}", file=sys.stderr)

    if failed:
        return 1

    views = Path(__file__).resolve().parents[1] / "src" / "Resources" / "views"
    used: set[str] = set()
    for twig in views.rglob("*.twig"):
        used.update(twig_keys(twig.read_text(encoding="utf-8")))

    missing_in_en = sorted(used - reference)
    if missing_in_en:
        print("ERROR: Twig keys missing from en catalogue:", file=sys.stderr)
        for key in missing_in_en:
            print(f"  missing: {key}", file=sys.stderr)
        return 1

    print(
        f"Translation key parity OK ({len(REQUIRED_LOCALES)} locales, {len(reference)} keys, {len(used)} used in Twig)."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
