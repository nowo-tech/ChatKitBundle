#!/usr/bin/env sh
set -eu

RAW_FILE="${1:-coverage-ts.txt}"

if [ ! -f "$RAW_FILE" ]; then
  echo "ERROR: coverage output file not found: $RAW_FILE" >&2
  exit 1
fi

VALUE="$(
  sed 's/\x1B\[[0-9;]*[A-Za-z]//g' "$RAW_FILE" \
    | awk '
      /^[[:space:]]*Statements[[:space:]]*:/ {
        for (i=1; i<=NF; i++) if ($i ~ /^[0-9]+(\.[0-9]+)?%$/) { gsub(/%/, "", $i); statements=$i; break }
      }
      /^[[:space:]]*Branches[[:space:]]*:/ {
        for (i=1; i<=NF; i++) if ($i ~ /^[0-9]+(\.[0-9]+)?%$/) { gsub(/%/, "", $i); branches=$i; break }
      }
      /^[[:space:]]*Functions[[:space:]]*:/ {
        for (i=1; i<=NF; i++) if ($i ~ /^[0-9]+(\.[0-9]+)?%$/) { gsub(/%/, "", $i); functions=$i; break }
      }
      /^[[:space:]]*Lines[[:space:]]*:/ {
        for (i=1; i<=NF; i++) if ($i ~ /^[0-9]+(\.[0-9]+)?%$/) { gsub(/%/, "", $i); lines=$i; break }
      }
      END {
        if (lines=="") exit 1
        printf "%.2f", lines+0
      }
    '
)"

if [ -z "${VALUE:-}" ]; then
  echo "ERROR: Could not extract TS coverage summary from ${RAW_FILE}" >&2
  exit 1
fi

echo "Global TS coverage (Lines): ${VALUE}%"

MIN_THRESHOLD="${TS_COVERAGE_MIN:-90}"
if awk "BEGIN { exit !(${VALUE} < ${MIN_THRESHOLD}) }"; then
  echo "ERROR: TS coverage ${VALUE}% is below minimum ${MIN_THRESHOLD}%" >&2
  exit 1
fi
