<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\ResponseDefinitions;
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
	 * is worked out up to `today`, or for an archived track up to its archive
	 * date if that is earlier; later ticks are ignored. Includes private tracks.
	 *
	 * @param string $today The client's date (YYYY-MM-DD); the server's date if omitted
	 * @param int $trackId One track only (also an archived one); 0 for all tracks
	 * @param bool $includeRuns Add every streak and break to each entry
	 * @param bool $includeArchived With all tracks, include archived ones
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyStats>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Stats returned
	 * 400: Today is not a valid date
	 * 404: Track not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/stats')]
	public function index(string $today = '', int $trackId = 0, bool $includeRuns = false, bool $includeArchived = false): DataResponse {
		try {
			$stats = $this->statsService->forUser($this->userId, $today, $trackId !== 0 ? $trackId : null, $includeRuns, $includeArchived);
			return new DataResponse($stats);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		}
	}
}
