#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Generate a somewhat realistic Tickbuddy demo dataset (JSON import format, version 1).

The dataset tells a small, positive story over a few years (by default from
2022-01-01 up to today): someone cutting down on alcohol (with believable
lapses, but trending the right way) while building an exercise habit, cutting
down on sweets, and keeping their coffee steady. Partway in they also take up
daily affirmations, and later set up a nightly backup whose cron job reports
to Tickbuddy through Tickcli. One winter they follow a Couch to 5k plan to the
end, then archive the track.

Notes are sprinkled over the hand-ticked tracks the way a person writes them:
now and then, in no fixed form, sometimes on a day without a tick. Nightly
backup is the exception: the cron job writes the same one-line report every
night, and on a failed run a note without a tick.

Storage is sparse, matching the app: boolean tracks emit a row only on "yes"
days; counter tracks emit a row only when the value is >= 1 (a zero means "no
row"). All track/tick/note shapes follow lib/Service/ImportService::importJson().

Usage:
    python3 generate-demo-data.py                 # writes tickbuddy-demo-data.json next to this script, ending today
    python3 generate-demo-data.py --seed 7        # a different-looking grid
    python3 generate-demo-data.py --start 2020-01-01 --end 2024-12-31
    python3 generate-demo-data.py --out /tmp/my-demo.json

Requires only the Python 3 standard library (no third-party packages).
"""
import argparse
import json
import random
from collections import defaultdict
from datetime import date, timedelta
from pathlib import Path


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="Generate a realistic Tickbuddy demo dataset (JSON, version 1).",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    p.add_argument("--start", type=date.fromisoformat, default=date(2022, 1, 1),
                   help="First day (inclusive), YYYY-MM-DD.")
    p.add_argument("--end", type=date.fromisoformat, default=date.today(),
                   help="Last day (inclusive), YYYY-MM-DD. Defaults to today.")
    p.add_argument("--seed", type=int, default=42,
                   help="Random seed; the same seed reproduces the same dataset.")
    p.add_argument("--out", type=Path,
                   default=Path(__file__).with_name("tickbuddy-demo-data.json"),
                   help="Output file path.")
    p.add_argument("--quiet", action="store_true", help="Suppress the summary table.")
    return p.parse_args()


# --- Alcohol: explicit episodes over the timeline (start_frac, end_frac, prob)
# Cutting down. The fractions are relative to the whole date range, so the
# story keeps its shape no matter what --start/--end you choose. Weekends add
# to these (see generate()).
ALCOHOL_SEGMENTS = [
    (0.00, 0.28, 0.62),   # a drink most evenings, without thinking about it
    (0.28, 0.31, 0.06),   # first dry month
    (0.31, 0.37, 0.50),   # back to old habits, a bit less
    (0.37, 0.45, 0.38),   # weekdays mostly dry, still trying
    (0.45, 0.49, 0.05),   # dry month #2
    (0.49, 0.54, 0.36),   # holidays
    (0.54, 0.63, 0.24),   # gradual improvement
    (0.63, 0.68, 0.14),   # doing well
    (0.68, 0.71, 0.45),   # stressful stretch
    (0.71, 0.78, 0.18),   # recovering
    (0.78, 0.86, 0.10),   # mostly weekends only
    (0.86, 0.88, 0.35),   # a summer of parties
    (0.88, 0.94, 0.08),   # nearly there
    (0.94, 1.01, 0.04),   # the odd glass on special occasions
]

TRACKS = [
    {"name": "Cups of coffee", "type": "counter", "sortOrder": 1, "private": False},
    {"name": "Alcohol", "type": "boolean", "sortOrder": 2, "private": False},
    {"name": "Sweets", "type": "counter", "sortOrder": 3, "private": False},
    {"name": "Act of kindness", "type": "boolean", "sortOrder": 4, "private": False},
    {"name": "Exercise", "type": "boolean", "sortOrder": 5, "private": False},
    {"name": "Affirmations", "type": "boolean", "sortOrder": 6, "private": False},
    {"name": "Nightly backup", "type": "boolean", "sortOrder": 7, "private": False},
    # Archived, so screenshots and manual checks show the archived list. Only
    # archived tracks carry the key, as the export writes them.
    {"name": "Couch to 5k", "type": "boolean", "sortOrder": 8, "private": False,
     "archivedAt": "2026-03-02"},
]

# Couch to 5k: three runs a week (Mon, Wed, Fri) over a fixed winter, ending
# with the graduation run on the day the track is archived. Fixed dates rather
# than fractions, so the archive date stays real whatever --start/--end say.
C25K_START = date(2025, 12, 1)
C25K_END = date(2026, 3, 2)
C25K_DAYS = (0, 2, 4)

# Affirmations are taken up during the first dry month, so the track has no
# history before this point (fraction of the whole range).
AFFIRMATIONS_START = 0.28

# The backup cron job is set up here (fraction of the whole range).
BACKUP_START = 0.40

# --- Notes. Each hand-ticked track has its own chance of a note on a day (a
# different one for ticked and unticked days) and its own pool of texts. The
# chance drifts with a slow "mood" per track, so notes come in bursts and dry
# spells rather than evenly.
NOTES = {
    "Cups of coffee": {
        "ticked": 0.035, "unticked": 0.0,
        "texts": [
            "New beans from the market, a bit too sour",
            "Long meeting day",
            "decaf after lunch",
            "Tried the oat milk flat white. Not bad!",
            "Couldn't sleep last night, needed them",
            "Office machine broken, so only one",
            "Cold brew because of the heat",
            "Coffee with an old friend",
            "too many",
            "Trying to stop after 2pm",
            "Deadline.",
            "Espresso at the station, then another at work",
        ],
    },
    "Alcohol": {
        "ticked": 0.14, "unticked": 0.012,
        "texts_ticked": [
            "One glass of wine with dinner",
            "Birthday party",
            "Two beers after football",
            "Work drinks, left early",
            "Wedding!",
            "Glass of red, long week",
            "Bad day. Not proud of it.",
            "Barbecue at the neighbours'",
            "Only one, then sparkling water",
            "New Year's Eve",
            "Pub quiz (we came second)",
            "Champagne for the promotion",
            "Had a gin & tonic on the balcony\nfelt fine about it",
        ],
        "texts_unticked": [
            "Said no at the after-work drinks",
            "Alcohol-free beer, surprisingly good",
            "Wanted one, made tea instead",
            "Dinner out, stuck to water",
            "Dry so far this month",
        ],
    },
    "Sweets": {
        "ticked": 0.03, "unticked": 0.006,
        "texts_ticked": [
            "Cake at work (someone's birthday)",
            "Ice cream in the park",
            "Chocolate. Stress.",
            "Grandma's apple pie, worth it",
            "Bag of gummy bears at the cinema",
            "Biscuits with tea",
            "Halloween leftovers",
        ],
        "texts_unticked": [
            "No sweets at all today",
            "Fruit instead of dessert",
        ],
    },
    "Act of kindness": {
        "ticked": 0.09, "unticked": 0.0,
        "texts": [
            "Helped the neighbour carry her shopping upstairs",
            "Paid for the coffee of the person behind me",
            "Called my aunt, she's been lonely",
            "Gave my seat on the tram",
            "Left a good review for the bakery",
            "Brought soup to Sam, who's ill",
            "Thanked the bus driver properly",
            "Returned a lost wallet to the shop",
            "Babysat so my sister could go out",
            "Wrote a thank-you note to my old teacher",
            "Donated blood",
            "Helped a tourist find the station",
            "Fixed the neighbour's bike chain",
            "Complimented a colleague's talk in front of the team",
        ],
    },
    "Exercise": {
        "ticked": 0.22, "unticked": 0.015,
        "texts_ticked": [
            "Run 5k",
            "5k run, 31:20",
            "Gym: legs",
            "gym - upper body",
            "Swimming, 1 km",
            "Yoga class",
            "Bike to work and back",
            "Long walk in the woods, 12 km",
            "Hiking with friends\n18 km, 600 m up",
            "Bouldering",
            "Run 8k, felt great",
            "Short HIIT session at home",
            "Tennis",
            "Climbed the stairs at work all week, counting it",
        ],
        "texts_unticked": [
            "Skipped, knee hurts",
            "Rest day",
            "Too tired after work",
            "Ill, staying in bed",
            "Rained all day, no excuse really",
        ],
    },
    "Affirmations": {
        "ticked": 0.05, "unticked": 0.008,
        "texts_ticked": [
            "I am patient with myself.",
            "I can do hard things",
            "Mirror, morning, three times",
            "\"Progress, not perfection\"",
            "Wrote them down instead of saying them",
            "I deserve rest.",
        ],
        "texts_unticked": [
            "Forgot, morning was chaos",
            "Felt silly today",
        ],
    },
}

# Couch to 5k: the plan's week and run on some run days, written differently
# each time, and a proud note on the graduation run.
C25K_NOTE_FORMS = ["W{w}D{d}", "Week {w}, day {d}", "w{w}d{d} done", "Week {w} day {d} - tough"]
C25K_GRADUATION = "Graduated! 5k without stopping, 33:48 🎉"

# Nightly backup: what the cron job's Tickcli call writes. The same line every
# night; a failed run leaves the reason and no tick.
BACKUP_FAILURES = [
    "FAILED (exit 2): NAS not reachable",
    "FAILED (exit 1): repository locked by another process",
    "FAILED (exit 2): disk full on /mnt/backup",
]


def lerp(a: float, b: float, t: float) -> float:
    return a + (b - a) * t


def alcohol_prob(f: float) -> float:
    for lo, hi, p in ALCOHOL_SEGMENTS:
        if lo <= f < hi:
            return p
    return ALCOHOL_SEGMENTS[-1][2]


def fmt_duration(seconds: int) -> str:
    return f"{seconds // 60}m{seconds % 60:02d}s"


def generate(start: date, end: date, seed: int) -> dict:
    if end < start:
        raise SystemExit("--end must not be before --start")
    random.seed(seed)
    # The later tracks draw from their own stream, so adding them left the
    # original tracks' ticks unchanged for a given seed.
    habits = random.Random(seed + 1)
    # Its own stream again, so adding Couch to 5k changed no other track's ticks.
    c25k = random.Random(seed + 2)
    # And again for notes and the backup, so they never shift any ticks.
    notes_rng = random.Random(seed + 3)
    backup = random.Random(seed + 4)
    affirmed_yesterday = False

    days = []
    d = start
    while d <= end:
        days.append(d)
        d += timedelta(days=1)
    total = len(days)

    ticks: list[dict] = []
    notes: dict[tuple[str, date], str] = {}
    # A slow random walk per track: notes come in bursts and dry spells.
    note_mood = {track: 1.0 for track in NOTES}
    backup_size = 41.0   # GB
    backup_failure = ""  # the reason while a failing run lasts
    backup_fail_days = 0
    c25k_run = 0

    def add(track: str, day: date, value: int = 1) -> None:
        # Sparse storage: only "yes" / non-zero days get a row.
        if value >= 1:
            ticks.append({"track": track, "date": day.isoformat(), "value": int(value)})

    def maybe_note(track: str, day: date, ticked: bool) -> None:
        cfg = NOTES[track]
        p = (cfg["ticked"] if ticked else cfg["unticked"]) * note_mood[track]
        if notes_rng.random() < p:
            pool = cfg.get("texts") or cfg["texts_ticked" if ticked else "texts_unticked"]
            notes[(track, day)] = notes_rng.choice(pool)

    for i, day in enumerate(days):
        f = i / (total - 1) if total > 1 else 0.0   # 0.0 at start -> 1.0 at end
        weekend = day.weekday() >= 5
        for track in note_mood:
            note_mood[track] = min(2.5, max(0.0, note_mood[track] + notes_rng.gauss(0, 0.12)))

        ap = alcohol_prob(f)
        drank = random.random() < min(0.95, ap + (0.18 if weekend else 0.0))

        # Alcohol
        if drank:
            add("Alcohol", day)
        maybe_note("Alcohol", day, drank)

        # Exercise: ramps up over time, weekend boost, lifts as drinking fades.
        ex_p = lerp(0.15, 0.60, f)
        if weekend:
            ex_p += 0.12
        ex_p += 0.10 * (1 - ap)          # fitter when not drinking
        exercised = random.random() < min(0.9, max(0.03, ex_p))
        if exercised:
            add("Exercise", day)
        maybe_note("Exercise", day, exercised)

        # Act of kindness: high and gently rising; small dip after a drink.
        sm_p = lerp(0.58, 0.82, f)
        if drank:
            sm_p -= 0.08
        kind = random.random() < min(0.95, max(0.2, sm_p))
        if kind:
            add("Act of kindness", day)
            maybe_note("Act of kindness", day, True)

        # Cups of coffee: 0-6, weekday-heavy, higher the morning after, drifts down.
        base = lerp(3.4, 2.6, f)
        if not weekend:
            base += 0.5
        if drank:
            base += 0.7
        cups = min(6, max(0, round(random.gauss(base, 1.1))))
        add("Cups of coffee", day, cups)
        if cups:
            maybe_note("Cups of coffee", day, True)

        # Sweets: gentle downward trend, but a sugar-craving bump during the
        # early dry stretches (fades as drinking less becomes normal). ~0-5.
        sweets_mean = lerp(2.6, 1.5, f)
        craving = (1 - ap) * (1 - f) * 1.8
        sweets = min(5, max(0, round(random.gauss(sweets_mean + craving, 1.1))))
        add("Sweets", day, sweets)
        maybe_note("Sweets", day, sweets >= 1)

        # Affirmations: none before they're taken up, then a streaky habit (a
        # day done makes the next more likely) that firms up over time. The
        # streak bonus lifts the realised rate above the base, so a 0.73 base
        # peaks at roughly 80% of days.
        affirmed = False
        if f >= AFFIRMATIONS_START:
            af_p = lerp(0.35, 0.73, (f - AFFIRMATIONS_START) / (1 - AFFIRMATIONS_START))
            af_p += 0.15 if affirmed_yesterday else -0.10
            if weekend:
                af_p -= 0.08              # routines slip at weekends
            affirmed = habits.random() < min(0.95, max(0.05, af_p))
            if affirmed:
                add("Affirmations", day)
            maybe_note("Affirmations", day, affirmed)
        affirmed_yesterday = affirmed

        # Nightly backup: the cron job ticks the day of every successful run
        # and always writes its one-line report. Failures are rare and come
        # in short runs (the NAS stays off until someone notices).
        if f >= BACKUP_START:
            if backup_fail_days == 0 and backup.random() < 0.012:
                backup_failure = backup.choice(BACKUP_FAILURES)
                backup_fail_days = backup.choice((1, 1, 1, 2, 3))
            if backup_fail_days > 0:
                notes[("Nightly backup", day)] = backup_failure
                backup_fail_days -= 1
            else:
                backup_size += max(0.0, backup.gauss(0.02, 0.03))
                changed = backup.randint(40, 2500) + (3000 if weekend else 0)
                secs = backup.randint(150, 330) + changed // 20
                notes[("Nightly backup", day)] = (
                    f"OK: {changed} files changed, {backup_size:.1f} GB total, {fmt_duration(secs)}"
                )
                add("Nightly backup", day)

        # Couch to 5k: the odd run missed, never the last one.
        if C25K_START <= day <= C25K_END and day.weekday() in C25K_DAYS:
            c25k_run += 1
            if day == C25K_END:
                add("Couch to 5k", day)
                notes[("Couch to 5k", day)] = C25K_GRADUATION
            elif c25k.random() >= 0.08:
                add("Couch to 5k", day)
                if notes_rng.random() < 0.3:
                    w, r = divmod(c25k_run - 1, 3)
                    notes[("Couch to 5k", day)] = notes_rng.choice(C25K_NOTE_FORMS).format(w=w + 1, d=r + 1)

    return {
        "version": 1,
        "exportedAt": date.today().isoformat() + "T09:00:00+00:00",
        "tracks": TRACKS,
        "ticks": ticks,
        "notes": [
            {"track": track, "date": day.isoformat(), "text": text}
            for (track, day), text in sorted(notes.items(), key=lambda kv: (kv[0][1], kv[0][0]))
        ],
    }


def print_summary(data: dict, start: date, end: date) -> None:
    days = (end - start).days + 1
    per_year_days: dict[int, int] = defaultdict(int)
    d = start
    while d <= end:
        per_year_days[d.year] += 1
        d += timedelta(days=1)

    counts: dict[tuple[str, int], int] = defaultdict(int)
    for t in data["ticks"]:
        counts[(t["track"], int(t["date"][:4]))] += 1
    note_counts: dict[str, int] = defaultdict(int)
    for n in data["notes"]:
        note_counts[n["track"]] += 1

    print(f"Range: {start} .. {end}  ({days} days)")
    print(f"Total tick rows: {len(data['ticks'])}, notes: {len(data['notes'])}")
    print(f"{'year':6}{'days':>6}{'alcohol%':>10}{'exercise%':>11}{'affirm%':>9}{'backup%':>9}")
    for y in sorted(per_year_days):
        dd = per_year_days[y]
        alcohol = 100 * counts[("Alcohol", y)] / dd
        ex = 100 * counts[("Exercise", y)] / dd
        affirm = 100 * counts[("Affirmations", y)] / dd
        backed_up = 100 * counts[("Nightly backup", y)] / dd
        print(f"{y:<6}{dd:>6}{alcohol:>9.0f}%{ex:>10.0f}%{affirm:>8.0f}%{backed_up:>8.0f}%")
    print("Notes per track: " + ", ".join(f"{t} {c}" for t, c in sorted(note_counts.items())))


def main() -> None:
    args = parse_args()
    data = generate(args.start, args.end, args.seed)
    args.out.write_text(json.dumps(data, indent=2, ensure_ascii=False))
    if not args.quiet:
        print_summary(data, args.start, args.end)
        print(f"Wrote {args.out}")


if __name__ == "__main__":
    main()
