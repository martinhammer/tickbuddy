<?php

declare(strict_types=1);

namespace OCA\Tickbuddy;

/**
 * Every error answer of the app's controllers. `reason` is one of the values in
 * OCA\Tickbuddy\Service\ErrorReason; a plain string, so a new value stays additive.
 *
 * @psalm-type TickbuddyError = array{
 *     message: string,
 *     reason: string,
 * }
 *
 * One option of a Choice track: `value` is its place, what a day stores.
 *
 * @psalm-type TickbuddyTrackOption = array{
 *     value: int,
 *     label: string,
 * }
 *
 * A track. `type` is "boolean", "counter", "number" or "choice" (and maybe
 * more later). A Number's `unit` ("" when none), `decimalPlaces` and
 * `allowNegative`, and a Choice's `options` and `orderedScale`, are null on
 * every other type.
 *
 * @psalm-type TickbuddyTrack = array{
 *     id: int,
 *     name: string,
 *     type: string,
 *     sortOrder: int,
 *     private: bool,
 *     archivedAt: ?string,
 *     publicLinkCount: int,
 *     unit: ?string,
 *     decimalPlaces: ?int,
 *     allowNegative: ?bool,
 *     options: ?list<TickbuddyTrackOption>,
 *     orderedScale: ?bool,
 * }
 *
 * A public link. `permission` is "tick" or "view"; `allowedDays` one of
 * Editable days' values; `lastUsedAt` Unix seconds, null until first called.
 * The token appears only inside `url`.
 *
 * @psalm-type TickbuddyPublicLink = array{
 *     id: int,
 *     trackId: int,
 *     name: string,
 *     permission: string,
 *     allowedDays: string,
 *     url: string,
 *     lastUsedAt: ?int,
 * }
 *
 * A day's value: a Counter's count, 1 for Yes/No, a Number's value (a
 * decimal, or a whole number written without a point), a Choice's place.
 *
 * @psalm-type TickbuddyTick = array{
 *     id: int,
 *     trackId: int,
 *     date: string,
 *     value: int|float,
 * }
 *
 * @psalm-type TickbuddyTickBounds = array{
 *     trackId: int,
 *     oldest: string,
 *     newest: string,
 * }
 *
 * @psalm-type TickbuddyNoteBounds = array{
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
 * What a View only public link answers
 *
 * @psalm-type TickbuddyPingView = array{
 *     ticks: list<TickbuddyTick>,
 *     notes?: list<TickbuddyNote>,
 * }
 *
 * @psalm-type TickbuddyRun = array{
 *     isStreak: bool,
 *     length: int,
 *     from: string,
 *     to: string,
 * }
 *
 * One track's stats. On a Number or Choice track every day with an entry
 * counts 1, and `maxValue`/`minValue` are the highest and lowest entry (null
 * without one, and always null on a Choice that isn't an ordered scale).
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
 *     maxValue: int|float|null,
 *     minValue: int|float|null,
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
 *     journalLayoutDesktop: string,
 *     journalLayoutMobile: string,
 *     showPrivateDesktop: bool,
 *     showPrivateMobile: bool,
 *     editableDaysDesktop: string,
 *     editableDaysMobile: string,
 * }
 *
 * @psalm-suppress UnusedClass
 */
class ResponseDefinitions {
}
