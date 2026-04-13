<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\TickService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
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

	private function serializeTick(Tick $tick): array {
		return [
			'id' => $tick->getId(),
			'trackId' => $tick->getTrackId(),
			'date' => $tick->getDate(),
			'value' => $tick->getValue(),
		];
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/ticks')]
	public function index(): DataResponse {
		$from = (string)$this->request->getParam('from', '');
		$to = (string)$this->request->getParam('to', '');
		$ticks = $this->tickService->findByDateRange($this->userId, $from, $to);
		return new DataResponse(array_map(fn (Tick $t) => $this->serializeTick($t), $ticks));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/ticks/toggle')]
	public function toggle(): DataResponse {
		$trackId = (int)$this->request->getParam('trackId', 0);
		$date = (string)$this->request->getParam('date', '');

		try {
			$ticked = $this->tickService->toggle($this->userId, $trackId, $date);
			return new DataResponse(['ticked' => $ticked]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidTrackTypeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/ticks/set')]
	public function set(): DataResponse {
		$trackId = (int)$this->request->getParam('trackId', 0);
		$date = (string)$this->request->getParam('date', '');
		$value = (int)$this->request->getParam('value', 0);

		try {
			$newValue = $this->tickService->set($this->userId, $trackId, $date, $value);
			return new DataResponse(['value' => $newValue]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidTrackTypeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
