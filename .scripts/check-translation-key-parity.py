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
    pattern = re.compile(
        r"'([a-z0-9_.]+)'\|trans\(\{\}, 'NowoChatKitBundle'\)"
    )
    for twig in views.rglob("*.twig"):
        used.update(pattern.findall(twig.read_text(encoding="utf-8")))

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
