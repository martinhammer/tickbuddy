<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\Db\IdempotencyKey;
use OCA\Tickbuddy\Db\IdempotencyKeyMapper;
use OCA\Tickbuddy\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * `Idempotency-Key` (draft-ietf-httpapi-idempotency-key-header) on the writes that
 * aren't safe to repeat: a client resends the same key with the same request on a
 * retry, and gets the first request's answer instead of the write happening twice.
 *
 * The key is claimed in its own row before the write runs, and the write itself is
 * left as it was: no transaction around it, because the increment and note writes
 * rely on catching unique violations, which abort a whole transaction on PostgreSQL.
 *
 * @psalm-import-type TickbuddyError from ResponseDefinitions
 */
class IdempotencyService {
	/** How long keys are kept, published to clients in mobile_instructions.md */
	public const KEEP_DAYS = 30;
	/** After this long without an answer, a claim's request is taken to have died */
	public const CLAIM_TIMEOUT_SECONDS = 300;
	/** Claim attempts before a repeat racing others is told to try again */
	private const MAX_CLAIMS = 3;

	public function __construct(
		private IRequest $request,
		private IdempotencyKeyMapper $mapper,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Run a write, honouring the request's `Idempotency-Key` if it sent one.
	 *
	 * @template T of DataResponse
	 * @param string $endpoint The endpoint's name, such as `ticks.increment`
	 * @param list<mixed> $params Every parameter the controller method takes, in its signature's order
	 * @param callable(): T $handler The endpoint as it is without a key; every outcome a DataResponse
	 * @return T|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_CONFLICT|Http::STATUS_UNPROCESSABLE_ENTITY, TickbuddyError, array{}>
	 */
	public function run(string $userId, string $endpoint, array $params, callable $handler): DataResponse {
		$header = $this->request->getHeader('Idempotency-Key');
		if ($header === '') {
			return $handler();
		}
		$key = self::parseKey($header);
		if ($key === null) {
			return new DataResponse(['message' => 'Idempotency-Key must be a UUID', 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		}
		$fingerprint = self::fingerprint($endpoint, $params);

		for ($attempt = 0; $attempt < self::MAX_CLAIMS; $attempt++) {
			$now = $this->timeFactory->getTime();
			$claim = $this->claim($userId, $key, $fingerprint, $now);
			if ($claim === null) {
				try {
					$row = $this->mapper->find($userId, $key);
				} catch (DoesNotExistException) {
					// Released or purged in between: claim again
					continue;
				}
				if ($row->getFingerprint() !== $fingerprint) {
					return new DataResponse(['message' => 'This Idempotency-Key was already used for another request', 'reason' => ErrorReason::IDEMPOTENCY_KEY_REUSED], Http::STATUS_UNPROCESSABLE_ENTITY);
				}
				/** @var Http::STATUS_*|null $status */
				$status = $row->getStatus();
				if ($status !== null) {
					/** @var array|scalar|null $data */
					$data = json_decode($row->getResponse() ?? 'null', true, 512, JSON_THROW_ON_ERROR);
					/** @var T $replay The stored answer is one this endpoint gave */
					$replay = new DataResponse($data, $status);
					return $replay;
				}
				$staleBefore = $now - self::CLAIM_TIMEOUT_SECONDS;
				if ($row->getCreatedAt() >= $staleBefore) {
					return $this->inProgress();
				}
				// The first request died before its answer was stored: handle this
				// one as new. If another repeat released it first, the next claim
				// meets that repeat's claim and answers 409.
				$this->mapper->releaseStale($row->getId(), $staleBefore);
				continue;
			}

			$response = $handler();
			try {
				$this->mapper->storeAnswer($claim->getId(), $response->getStatus(), json_encode($response->getData(), JSON_THROW_ON_ERROR));
			} catch (DbException|\JsonException $e) {
				// The write is done, so answer it; a repeat waits for the claim to go stale
				$this->logger->warning('Could not store the answer for an Idempotency-Key', ['exception' => $e]);
			}
			return $response;
		}

		return $this->inProgress();
	}

	/**
	 * @return ?IdempotencyKey Null when the key is already claimed
	 */
	private function claim(string $userId, string $key, string $fingerprint, int $now): ?IdempotencyKey {
		try {
			return $this->mapper->claim($userId, $key, $fingerprint, $now);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			return null;
		}
	}

	/**
	 * @return DataResponse<Http::STATUS_CONFLICT, TickbuddyError, array{}>
	 */
	private function inProgress(): DataResponse {
		return new DataResponse(['message' => 'This request is still being handled; try again shortly', 'reason' => ErrorReason::REQUEST_IN_PROGRESS], Http::STATUS_CONFLICT);
	}

	/**
	 * The key from an `Idempotency-Key` header, lower-cased, or null if it isn't a
	 * UUID. The header is a Structured Fields string, so in double quotes, but a
	 * bare UUID is accepted too.
	 *
	 * @internal Public for the tests
	 */
	public static function parseKey(string $header): ?string {
		$key = trim($header);
		if (strlen($key) >= 2 && str_starts_with($key, '"') && str_ends_with($key, '"')) {
			$key = substr($key, 1, -1);
		}
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $key) !== 1) {
			return null;
		}
		return strtolower($key);
	}

	/**
	 * What makes two requests "the same": the endpoint and every parameter, as
	 * received. serialize() rather than JSON: it keeps every type apart (1, "1",
	 * true, 1.0, null and "") and can't fail on text that isn't UTF-8.
	 *
	 * @param list<mixed> $params
	 * @internal Public for the tests
	 */
	public static function fingerprint(string $endpoint, array $params): string {
		return hash('sha256', serialize([$endpoint, ...$params]));
	}
}
