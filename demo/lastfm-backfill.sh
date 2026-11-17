#!/usr/bin/env bash
#
# Backfills the Music track: runs lastfm-to-tickbuddy.sh for every day from
# 1 January this year up to two days ago, one at a time.
#
# Usage: lastfm-backfill.sh [--no-note] [--dry-run]
#
# The options are passed on to lastfm-to-tickbuddy.sh. The public link's
# allowed days must be "All" while this runs (the days go back months);
# set it back afterwards.
#
# The pause keeps well under Tickbuddy's limit for public links (60 calls a
# minute per calling address). A failed day is reported and skipped; the exit
# status is 1 if any day failed, so those days can be run again by hand.

set -uo pipefail

script=$(dirname "$0")/lastfm-to-tickbuddy.sh
pause=2

day=$(date +%Y-01-01)
last=$(date -d '2 days ago' +%F)
failed=()

while [[ $day < $last || $day == "$last" ]]; do
	"$script" "$@" "$day" || failed+=("$day")
	day=$(date -d "$day + 1 day" +%F)
	[[ $day > $last ]] || sleep "$pause"
done

if ((${#failed[@]})); then
	echo "Failed days: ${failed[*]}" >&2
	exit 1
fi
