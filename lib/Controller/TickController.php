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
	 * @param string|int|float|bool|null $note Optional note for the day: replaces the day's note, empty deletes it, missing leaves it
	 * @return DataResponse<Http::STATUS_OK, array{ticked: bool, note?: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Tick toggled; `note` is the day's stored note, only when one was sent
	 * 400: Track is not a boolean track, or the date or the note is invalid
	 * 404: Track not found
	 */
	// $note is a union for the framework: typed string, Nextcloud would answer a
	// bare 400 to a JSON number. NoteService::normalise reads it.
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/ticks/toggle')]
	public function toggle(int $trackId = 0, string $date = '', string|int|float|bool|null $note = null): DataResponse {
		try {
			return new DataResponse($this->tickService->toggle($this->userId, $trackId, $date, $note));
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
	 * @param string|int|float|bool|null $note Optional note for the day: replaces the day's note, empty deletes it, missing leaves it
	 * @return DataResponse<Http::STATUS_OK, array{value: int|bool, note?: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Value set; `note` is the day's stored note, only when one was sent
	 * 400: Value not valid for a yes/no track, or the date or the note is invalid
	 * 404: Track not found
	 */
	// $value is untyped for the framework: typed int, Nextcloud would turn
	// "true" or "on" into 0 before the service sees it.
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/ticks/set')]
	public function set(int $trackId = 0, string $date = '', int|bool|string|null $value = null, string|int|float|bool|null $note = null): DataResponse {
		try {
			return new DataResponse($this->tickService->set($this->userId, $trackId, $date, $value, $note));
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
	 * @param string|int|float|bool|null $note Optional note for the day: replaces the day's note, empty deletes it, missing leaves it
	 * @return DataResponse<Http::STATUS_OK, array{value: int, note?: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Value changed; `note` is the day's stored note, only when one was sent
	 * 400: Not a counter track, or delta, date or note invalid
	 * 404: Track not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/ticks/increment')]
	public function increment(int $trackId = 0, string $date = '', int $delta = 0, string|int|float|bool|null $note = null): DataResponse {
		try {
			return new DataResponse($this->tickService->increment($this->userId, $trackId, $date, $delta, $note));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidTrackTypeException|InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
