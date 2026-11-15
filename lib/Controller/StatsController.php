<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\ErrorReason;
use OCA\Tickbuddy\Service\Flags;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\StatsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type TickbuddyError from ResponseDefinitions
 * @psalm-import-type TickbuddyStats from ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class StatsController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private StatsService $statsService,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Stats and streaks per track
	 *
	 * Every track gets an entry, also with no ticks, in track list order. Each
	 * is worked out up to the earliest of `to`, `today` and, for an archived
	 * track, its archive date; later ticks are ignored. With `from`, every day
	 * of the period from `from` takes part (unticked days at its start are a
	 * break), and streaks and breaks crossing either edge are cut there.
	 * Includes private tracks. Check the `statsPeriod` capability before
	 * sending `from` or `to`: older servers ignore them and answer all-time stats.
	 *
	 * @param string $today The client's date (YYYY-MM-DD); the server's date if omitted
	 * @param string $from The period's first day (YYYY-MM-DD); from the first tick if omitted
	 * @param string $to The period's last day (YYYY-MM-DD); up to today if omitted
	 * @param int $trackId One track only (also an archived one); 0 for all tracks
	 * @param string|int|float|bool|null $includeRuns Add every streak and break to each entry: true or false (also 1/0, on/off, yes/no)
	 * @param string|int|float|bool|null $includeArchived With all tracks, include archived ones: true or false, as `includeRuns`
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyStats>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, TickbuddyError, array{}>
	 *
	 * 200: Stats returned
	 * 400: Today, from or to is not a valid date, or from is after to, or a flag isn't true or false
	 * 404: Track not found
	 */
	// Flags are unions for the framework: typed bool, Nextcloud would read "no" as true
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/stats')]
	public function index(string $today = '', string $from = '', string $to = '', int $trackId = 0, string|int|float|bool|null $includeRuns = false, string|int|float|bool|null $includeArchived = false): DataResponse {
		try {
			$includeRuns = Flags::read('includeRuns', $includeRuns, false);
			$includeArchived = Flags::read('includeArchived', $includeArchived, false);
			$stats = $this->statsService->forUser($this->userId, $today, $trackId !== 0 ? $trackId : null, $includeRuns, $includeArchived, $from, $to);
			return new DataResponse($stats);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
		}
	}
}
