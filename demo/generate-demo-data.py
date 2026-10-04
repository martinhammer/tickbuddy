#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Generate a somewhat realistic Tickbuddy demo dataset (JSON import format, version 1).

The dataset tells a small, positive story over a few years (by default from
2022-01-01 up to today): someone quitting
smoking (with believable relapses, but trending the right way) while building
an exercise habit, cutting down on sweets, and keeping their coffee steady.
Partway in they also take up daily affirmations, and slowly win back their
evenings from doomscrolling.

Storage is sparse, matching the app: boolean tracks emit a row only on "yes"
days; counter tracks emit a row only when the value is >= 1 (a zero means "no
row"). All track/tick shapes follow lib/Service/ImportService::importJson().

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


# --- Smoking: explicit episodes over the timeline (start_frac, end_frac, prob)
# A quit journey. The fractions are relative to the whole date range, so the
# story keeps its shape no matter what --start/--end you choose.
SMOKE_SEGMENTS = [
    (0.00, 0.28, 0.95),   # heavy smoker, pre-contemplation
    (0.28, 0.31, 0.12),   # first serious quit attempt (New Year)
    (0.31, 0.37, 0.78),   # relapse (but never quite as heavy again)
    (0.37, 0.45, 0.45),   # mixed, still trying
    (0.45, 0.49, 0.10),   # quit attempt #2
    (0.49, 0.54, 0.52),   # a slip
    (0.54, 0.63, 0.28),   # gradual improvement
    (0.63, 0.68, 0.15),   # doing well
    (0.68, 0.71, 0.60),   # stress relapse cluster
    (0.71, 0.78, 0.18),   # recovering
    (0.78, 0.86, 0.07),   # mostly quit
    (0.86, 0.88, 0.42),   # brief slip
    (0.88, 0.94, 0.05),   # nearly there
    (0.94, 1.01, 0.02),   # essentially smoke-free, rare slips
]

TRACKS = [
    {"name": "Cups of coffee", "type": "counter", "sortOrder": 1, "private": False},
    {"name": "Smoking", "type": "boolean", "sortOrder": 2, "private": False},
    {"name": "Exercise", "type": "boolean", "sortOrder": 3, "private": False},
    {"name": "Made someone smile", "type": "boolean", "sortOrder": 4, "private": False},
    {"name": "Sweets", "type": "counter", "sortOrder": 5, "private": False},
    {"name": "Affirmations", "type": "boolean", "sortOrder": 6, "private": False},
    {"name": "Zero doomscrolling", "type": "boolean", "sortOrder": 7, "private": False},
]

# Affirmations are taken up during the first serious quit attempt, so the track
# has no history before this point (fraction of the whole range).
AFFIRMATIONS_START = 0.28


def lerp(a: float, b: float, t: float) -> float:
    return a + (b - a) * t


def smoke_prob(f: float) -> float:
    for lo, hi, p in SMOKE_SEGMENTS:
        if lo <= f < hi:
            return p
    return SMOKE_SEGMENTS[-1][2]


def generate(start: date, end: date, seed: int) -> dict:
    if end < start:
        raise SystemExit("--end must not be before --start")
    random.seed(seed)
    # The later tracks draw from their own stream, so adding them left the
    # original five tracks' ticks unchanged for a given seed.
    habits = random.Random(seed + 1)
    affirmed_yesterday = False
    scrolled_yesterday = True

    days = []
    d = start
    while d <= end:
        days.append(d)
        d += timedelta(days=1)
    total = len(days)

    ticks: list[dict] = []

    def add(track: str, day: date, value: int = 1) -> None:
        # Sparse storage: only "yes" / non-zero days get a row.
        if value >= 1:
            ticks.append({"track": track, "date": day.isoformat(), "value": int(value)})

    for i, day in enumerate(days):
        f = i / (total - 1) if total > 1 else 0.0   # 0.0 at start -> 1.0 at end
        weekend = day.weekday() >= 5
        sp = smoke_prob(f)
        smoked = random.random() < sp

        # Smoking
        if smoked:
            add("Smoking", day)

        # Exercise: ramps up over time, weekend boost, lifts as smoking fades.
        ex_p = lerp(0.15, 0.60, f)
        if weekend:
            ex_p += 0.12
        ex_p += 0.10 * (1 - sp)          # healthier when not smoking
        exercised = random.random() < min(0.9, max(0.03, ex_p))
        if exercised:
            add("Exercise", day)

        # Made someone smile: high and gently rising; small dip on smoking days.
        sm_p = lerp(0.58, 0.82, f)
        if smoked:
            sm_p -= 0.08
        if random.random() < min(0.95, max(0.2, sm_p)):
            add("Made someone smile", day)

        # Cups of coffee: 0-6, weekday-heavy, higher when smoking, drifts down.
        base = lerp(3.4, 2.6, f)
        if not weekend:
            base += 0.5
        if smoked:
            base += 0.7                   # coffee-and-cigarette
        add("Cups of coffee", day, min(6, max(0, round(random.gauss(base, 1.1)))))

        # Sweets: gentle downward trend, but a comfort-eating bump during the
        # *struggle* of an early quit attempt (fades once smoke-free). ~0-5.
        sweets_mean = lerp(2.6, 1.5, f)
        craving = (1 - sp) * (1 - f) * 1.8
        add("Sweets", day, min(5, max(0, round(random.gauss(sweets_mean + craving, 1.1)))))

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
        affirmed_yesterday = affirmed

        # Zero doomscrolling ("yes" = a clean day): rare at first, improving
        # steadily. Worse at weekends and on smoking (stress) days; better on
        # exercise and affirmation days. Bad nights tend to come in runs.
        nd_p = lerp(0.12, 0.65, f)
        if weekend:
            nd_p -= 0.12
        if smoked:
            nd_p -= 0.08
        if exercised:
            nd_p += 0.08
        if affirmed:
            nd_p += 0.06
        if scrolled_yesterday:
            nd_p -= 0.08
        clean = habits.random() < min(0.9, max(0.03, nd_p))
        if clean:
            add("Zero doomscrolling", day)
        scrolled_yesterday = not clean

    return {
        "version": 1,
        "exportedAt": date.today().isoformat() + "T09:00:00+00:00",
        "tracks": TRACKS,
        "ticks": ticks,
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

    print(f"Range: {start} .. {end}  ({days} days)")
    print(f"Total tick rows: {len(data['ticks'])}")
    print(f"{'year':6}{'days':>6}{'smoke%':>8}{'exercise%':>11}{'affirm%':>9}{'nodoom%':>9}")
    for y in sorted(per_year_days):
        dd = per_year_days[y]
        smoke = 100 * counts[("Smoking", y)] / dd
        ex = 100 * counts[("Exercise", y)] / dd
        affirm = 100 * counts[("Affirmations", y)] / dd
        nodoom = 100 * counts[("Zero doomscrolling", y)] / dd
        print(f"{y:<6}{dd:>6}{smoke:>7.0f}%{ex:>10.0f}%{affirm:>8.0f}%{nodoom:>8.0f}%")


def main() -> None:
    args = parse_args()
    data = generate(args.start, args.end, args.seed)
    args.out.write_text(json.dumps(data, indent=2))
    if not args.quiet:
        print_summary(data, args.start, args.end)
        print(f"Wrote {args.out}")


if __name__ == "__main__":
    main()
