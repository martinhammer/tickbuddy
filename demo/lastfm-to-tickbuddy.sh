#!/usr/bin/env bash
#
# Records a day's last.fm scrobbles on a Tickbuddy counter track through a
# public link: the number of songs as the day's value, and a one-line summary
# (distinct artists, most played artist) appended to the day's note.
#
# Usage: lastfm-to-tickbuddy.sh [--no-note] [--dry-run] [YYYY-MM-DD]
#
# The day defaults to yesterday, which is finished, so the count is final.
# Run it shortly after midnight, for example with this crontab line:
#
#   5 0 * * * /path/to/lastfm-to-tickbuddy.sh
#
# The value is sent as the day's absolute count, so running it again for the
# same day is harmless. The note is appended (a link can't replace a note):
# the same line twice is skipped by Tickbuddy, but a line that changed (late
# scrobbles from an offline device) is added under the old one.
#
# Settings come from the environment, or from an env file
# (${XDG_CONFIG_HOME:-~/.config}/tickbuddy-lastfm.env, or $LASTFM_ENV):
#
#   LASTFM_API_KEY=...      from https://www.last.fm/api/account/create
#   LASTFM_USER=...         your last.fm username
#   TICKBUDDY_PING_URL=...  the "Tick only" public link of a counter track,
#                           https://cloud.example.com/ocs/v2.php/apps/tickbuddy/ping/<token>
#
# Keep the env file private (chmod 600): the link's token lets anyone tick.
# Its allowed days must include the day sent (+1 day covers yesterday).
#
# Needs bash, curl, jq and GNU date. Days are local days, so the machine's
# time zone (or TZ) should match the one in your Nextcloud profile.

set -euo pipefail

usage() {
	echo "Usage: $0 [--no-note] [--dry-run] [YYYY-MM-DD]" >&2
	exit 2
}

fail() {
	echo "$0: $*" >&2
	exit 1
}

note=1
dry_run=0
day=
for arg in "$@"; do
	case $arg in
		--no-note) note=0 ;;
		--dry-run) dry_run=1 ;;
		-h | --help) usage ;;
		-*) usage ;;
		*) [[ -z $day ]] || usage; day=$arg ;;
	esac
done

env_file=${LASTFM_ENV:-${XDG_CONFIG_HOME:-$HOME/.config}/tickbuddy-lastfm.env}
if [[ -f $env_file ]]; then
	# shellcheck source=/dev/null
	source "$env_file"
fi
: "${LASTFM_API_KEY:?is not set}" "${LASTFM_USER:?is not set}"
[[ $dry_run == 1 ]] || : "${TICKBUDDY_PING_URL:?is not set}"

for tool in curl jq date; do
	command -v "$tool" > /dev/null || fail "$tool is needed"
done

# The day as YYYY-MM-DD, and its local midnights as Unix seconds.
day=${day:-$(date -d yesterday +%F)}
[[ $day =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ && $(date -d "$day" +%F 2> /dev/null) == "$day" ]] ||
	fail "not a date as YYYY-MM-DD: $day"
from=$(date -d "$day 00:00" +%s)
until=$(date -d "$day 00:00 + 1 day" +%s)

# Every scrobble of the day, as one JSON array, page by page (200 at most per
# page). last.fm's `to` is inclusive, hence the second before midnight.
fetch_page() {
	curl -sS --fail-with-body --retry 3 --get https://ws.audioscrobbler.com/2.0/ \
		--data-urlencode method=user.getrecenttracks \
		--data-urlencode "user=$LASTFM_USER" \
		--data-urlencode "api_key=$LASTFM_API_KEY" \
		--data-urlencode format=json \
		--data-urlencode limit=200 \
		--data-urlencode "from=$from" \
		--data-urlencode "to=$((until - 1))" \
		--data-urlencode "page=$1"
}

tracks='[]'
page=1
while :; do
	response=$(fetch_page "$page") || fail "last.fm: $response"
	if jq -e '.error' <<< "$response" > /dev/null; then
		fail "last.fm: $(jq -r '.message' <<< "$response")"
	fi
	# A page of one track holds an object rather than a list. The playing
	# track has no date; anything outside the day is left out too.
	tracks=$(jq -c --argjson tracks "$tracks" --argjson from "$from" --argjson until "$until" '
		$tracks + ([.recenttracks.track // [] | if type == "array" then .[] else . end
			| select(.date.uts != null)
			| select((.date.uts | tonumber) as $t | $t >= $from and $t < $until)
			| {artist: .artist["#text"], name}])
	' <<< "$response")
	pages=$(jq -r '.recenttracks["@attr"].totalPages // "1" | tonumber' <<< "$response")
	((page < pages)) || break
	page=$((page + 1))
	sleep 0.25
done

count=$(jq 'length' <<< "$tracks")
summary=
if [[ $note == 1 ]] && ((count > 0)); then
	summary=$(jq -r '
		(map(.artist) | unique | length) as $artists
		| group_by(.artist) | max_by(length) as $top
		| "Last.fm: \($artists) artist\(if $artists == 1 then "" else "s" end), most played artist: \($top[0].artist) \($top | length)×"
	' <<< "$tracks")
	# Well within Tickbuddy's 500 characters, whatever the names.
	summary=${summary:0:400}
fi

echo "$day: $count scrobbles${summary:+ ($summary)}"
[[ $dry_run == 0 ]] || exit 0

# A counter's value sets the day (0 clears it); the note goes as the POST's
# text/plain body, which Tickbuddy appends to the day's note.
url="$TICKBUDDY_PING_URL?value=$count&date=$day"
if [[ -n $summary ]]; then
	answer=$(printf '%s' "$summary" | curl -sS --retry 3 -w '\n%{http_code}' -X POST \
		-H 'Content-Type: text/plain; charset=utf-8' --data-binary @- "$url")
else
	answer=$(curl -sS --retry 3 -w '\n%{http_code}' -X POST "$url")
fi
status=${answer##*$'\n'}
[[ $status == 200 ]] || fail "Tickbuddy answered $status: ${answer%$'\n'*}"
