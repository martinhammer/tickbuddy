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
 * @psalm-type TickbuddyImportResult = array{
 *     tracks: int,
 *     ticks: int,
 * }
 *
 * @psalm-type TickbuddyExport = array{
 *     version: int,
 *     exportedAt: string,
 *     tracks: list<array<string, mixed>>,
 *     ticks: list<array<string, mixed>>,
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
