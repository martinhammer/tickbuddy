<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\TickService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type TickbuddyTick from ResponseDefinitions
 * @psalm-import-type TickbuddyTickBounds from ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class TickController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private TickService $tickService,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return TickbuddyTick
	 */
	private function serializeTick(Tick $tick): array {
		return [
			'id' => $tick->getId(),
			'trackId' => $tick->getTrackId(),
			'date' => $tick->getDate(),
			'value' => $tick->getValue(),
		];
	}

	/**
	 * List ticks within a date range
	 *
	 * @param string $from Start date (YYYY-MM-DD), inclusive
	 * @param string $to End date (YYYY-MM-DD), inclusive
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyTick>, array{}>
	 *
	 * 200: Ticks returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/ticks')]
	public function index(string $from = '', string $to = ''): DataResponse {
		$ticks = $this->tickService->findByDateRange($this->userId, $from, $to);
		return new DataResponse(array_values(array_map(fn (Tick $t) => $this->serializeTick($t), $ticks)));
	}

	/**
	 * List the first and last tick date for each track
	 *
	 * Tracks that have no ticks are omitted. Includes private tracks; filter
	 * them client-side as with the tick list.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyTickBounds>, array{}>
	 *
	 * 200: Bounds returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/ticks/bounds')]
	public function bounds(): DataResponse {
		return new DataResponse($this->tickService->findBounds($this->userId));
	}

	/**
	 * Toggle a boolean tick on or off
	 *
	 * @param int $trackId Track ID
	 * @param string $date Date (YYYY-MM-DD)
	 * @return DataResponse<Http::STATUS_OK, array{ticked: bool}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Tick toggled
	 * 400: Track is not a boolean track, or the date is invalid
	 * 404: Track not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/ticks/toggle')]
	public function toggle(int $trackId = 0, string $date = ''): DataResponse {
		try {
			$ticked = $this->tickService->toggle($this->userId, $trackId, $date);
			return new DataResponse(['ticked' => $ticked]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidTrackTypeException|InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Set a tick (counter: its absolute value; yes/no: ticked or not)
	 *
	 * @param int $trackId Track ID
	 * @param string $date Date (YYYY-MM-DD)
	 * @param int|bool|string|null $value Counter: the new value (0 deletes the tick). Yes/no: true or false (also 1/0, on/off, yes/no)
	 * @return DataResponse<Http::STATUS_OK, array{value: int|bool}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Value set
	 * 400: Value not valid for a yes/no track, or the date is invalid
	 * 404: Track not found
	 */
	// $value is untyped for the framework: typed int, Nextcloud would turn
	// "true" or "on" into 0 before the service sees it.
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/ticks/set')]
	public function set(int $trackId = 0, string $date = '', int|bool|string|null $value = null): DataResponse {
		try {
			return new DataResponse($this->tickService->set($this->userId, $trackId, $date, $value));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidTrackTypeException|InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Add to a counter tick
	 *
	 * Concurrent increments of the same day, from any mix of clients, all
	 * count. The value never goes below 0; reaching 0 deletes the tick.
	 *
	 * @param int $trackId Track ID
	 * @param string $date Date (YYYY-MM-DD)
	 * @param int $delta Amount to add, not 0; negative to take away
	 * @return DataResponse<Http::STATUS_OK, array{value: int}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Value changed
	 * 400: Not a counter track, or delta or date invalid
	 * 404: Track not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/ticks/increment')]
	public function increment(int $trackId = 0, string $date = '', int $delta = 0): DataResponse {
		try {
			return new DataResponse(['value' => $this->tickService->increment($this->userId, $trackId, $date, $delta)]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidTrackTypeException|InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
