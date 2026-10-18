<?php

declare(strict_types=1);

namespace OCA\Tickbuddy;

/**
 * @psalm-type TickbuddyTrack = array{
 *     id: int,
 *     name: string,
 *     type: string,
 *     sortOrder: int,
 *     private: bool,
 *     archivedAt: ?string,
 * }
 *
 * @psalm-type TickbuddyTick = array{
 *     id: int,
 *     trackId: int,
 *     date: string,
 *     value: int,
 * }
 *
 * @psalm-type TickbuddyTickBounds = array{
 *     trackId: int,
 *     oldest: string,
 *     newest: string,
 * }
 *
 * @psalm-type TickbuddyNote = array{
 *     trackId: int,
 *     date: string,
 *     text: string,
 * }
 *
 * @psalm-type TickbuddyRun = array{
 *     isStreak: bool,
 *     length: int,
 *     from: string,
 *     to: string,
 * }
 *
 * @psalm-type TickbuddyStats = array{
 *     trackId: int,
 *     total: int,
 *     weeklyMean: float,
 *     last7: int,
 *     previous7: int,
 *     peak7: int,
 *     current: ?TickbuddyRun,
 *     upToYesterday: bool,
 *     longestStreak: ?TickbuddyRun,
 *     longestBreak: ?TickbuddyRun,
 *     firstTick: ?string,
 *     lastTick: ?string,
 *     maxValue: int,
 *     minValue: int,
 *     runs?: list<TickbuddyRun>,
 * }
 *
 * @psalm-type TickbuddyImportResult = array{
 *     tracks: int,
 *     ticks: int,
 *     notes: int,
 * }
 *
 * @psalm-type TickbuddyExport = array{
 *     version: int,
 *     exportedAt: string,
 *     tracks: list<array<string, mixed>>,
 *     ticks: list<array<string, mixed>>,
 *     notes?: list<array<string, mixed>>,
 * }
 *
 * @psalm-type TickbuddyPreferences = array{
 *     defaultView: string,
 * }
 *
 * @psalm-suppress UnusedClass
 */
class ResponseDefinitions {
}
