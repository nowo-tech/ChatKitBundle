#!/usr/bin/env sh
# Retry a command on transient network failures (GitHub 429/503/504, Composer dist).
# Usage: retry.sh [--attempts N] -- command [args...]
set -eu

MAX="${COMPOSER_RETRY_ATTEMPTS:-8}"
while [ "$#" -gt 0 ]; do
	case "$1" in
		--attempts)
			MAX="${2:?}"
			shift 2
			;;
		--)
			shift
			break
			;;
		*)
			break
			;;
	esac
done

if [ "$#" -lt 1 ]; then
	echo "usage: $0 [--attempts N] -- command [args...]" >&2
	exit 2
fi

n=0
while true; do
	n=$((n + 1))
	if "$@"; then
		exit 0
	fi
	if [ "$n" -ge "$MAX" ]; then
		echo "command failed after $n attempts: $*" >&2
		exit 1
	fi
	sleep_s=$((15 * n))
	if [ "$sleep_s" -gt 120 ]; then
		sleep_s=120
	fi
	echo "command failed (attempt $n/$MAX), retrying in ${sleep_s}s..." >&2
	sleep "$sleep_s"
done
