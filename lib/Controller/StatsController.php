<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\ErrorReason;
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
	 * @param bool $includeRuns Add every streak and break to each entry
	 * @param bool $includeArchived With all tracks, include archived ones
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyStats>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, TickbuddyError, array{}>
	 *
	 * 200: Stats returned
	 * 400: Today, from or to is not a valid date, or from is after to
	 * 404: Track not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/stats')]
	public function index(string $today = '', string $from = '', string $to = '', int $trackId = 0, bool $includeRuns = false, bool $includeArchived = false): DataResponse {
		try {
			$stats = $this->statsService->forUser($this->userId, $today, $trackId !== 0 ? $trackId : null, $includeRuns, $includeArchived, $from, $to);
			return new DataResponse($stats);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
		}
	}
}
