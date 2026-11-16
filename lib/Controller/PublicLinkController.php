<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\Db\PublicLink;
use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\ErrorReason;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\PublicLinkLimitReachedException;
use OCA\Tickbuddy\Service\PublicLinkService;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Managing public links, for the signed-in user (flag `publicLinks`). The
 * address each link holds is answered by {@see PingController}.
 *
 * @psalm-import-type TickbuddyError from ResponseDefinitions
 * @psalm-import-type TickbuddyPublicLink from ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class PublicLinkController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private PublicLinkService $links,
		private IdempotencyService $idempotency,
		private IURLGenerator $urlGenerator,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The address is built from the server's own settings (host, overwrite.cli.url),
	 * as Files' share links are.
	 *
	 * @return TickbuddyPublicLink
	 */
	private function serializeLink(PublicLink $link): array {
		return [
			'id' => $link->getId(),
			'trackId' => $link->getTrackId(),
			'name' => $link->getName(),
			'permission' => $link->getPermission(),
			'allowedDays' => $link->getAllowedDays(),
			'url' => $this->urlGenerator->linkToOCSRouteAbsolute('tickbuddy.ping.call', ['token' => $link->getToken()]),
			'lastUsedAt' => $link->getLastUsedAt(),
		];
	}

	/**
	 * List public links
	 *
	 * Revoked links are never listed, so an archived track has none.
	 *
	 * @param int $trackId One track's links; 0 for every track's
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyPublicLink>, array{}>|DataResponse<Http::STATUS_NOT_FOUND, TickbuddyError, array{}>
	 *
	 * 200: Links returned, oldest first
	 * 404: Track not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/public-links')]
	public function index(int $trackId = 0): DataResponse {
		try {
			$links = $this->links->list($this->userId, $trackId !== 0 ? $trackId : null);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse(array_values(array_map(fn (PublicLink $l) => $this->serializeLink($l), $links)));
	}

	/**
	 * Create a public link
	 *
	 * Permission and allowed days can't be changed later; only the name can.
	 *
	 * With an `Idempotency-Key` header (a UUID), a retry with the same key and
	 * the same parameters is answered as the first request was and changes
	 * nothing; see the `idempotencyKey` capability.
	 *
	 * @param int $trackId Track ID
	 * @param string $name Link name, 1 to 255 characters once trimmed; names may repeat
	 * @param string $permission "tick" or "view"
	 * @param string $allowedDays "activeDay", "activeAndPrevious", "oneWeek" or "allDays"
	 * @return DataResponse<Http::STATUS_CREATED, TickbuddyPublicLink, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 *
	 * 201: Link created. With `Idempotency-Key`: a repeat of an earlier request answers as that request did, and changes nothing
	 * 400: Invalid name, permission or allowed days, or `Idempotency-Key` is not a UUID
	 * 403: The track already has 99 public links
	 * 404: Track not found
	 * 409: A request with the same `Idempotency-Key` is still being handled; try again shortly
	 * 422: This `Idempotency-Key` was used for another request; nothing written
	 * 423: The track is archived, for every client; nothing written
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/public-links')]
	public function create(int $trackId = 0, string $name = '', string $permission = '', string $allowedDays = ''): DataResponse {
		return $this->idempotency->run($this->userId, 'publicLinks.create', [$trackId, $name, $permission, $allowedDays], function () use ($trackId, $name, $permission, $allowedDays): DataResponse {
			try {
				$link = $this->links->create($this->userId, $trackId, $name, $permission, $allowedDays);
				return new DataResponse($this->serializeLink($link), Http::STATUS_CREATED);
			} catch (DoesNotExistException) {
				return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
			} catch (ReadOnlyTrackException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_LOCKED);
			} catch (InvalidValueException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
			} catch (PublicLinkLimitReachedException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::PUBLIC_LINK_LIMIT_REACHED], Http::STATUS_FORBIDDEN);
			}
		});
	}

	/**
	 * Rename a public link
	 *
	 * Only the name can change: `permission` and `allowedDays` aren't
	 * parameters here, so they are ignored if sent.
	 *
	 * @param int $id Link ID
	 * @param string $name New name, 1 to 255 characters once trimmed
	 * @return DataResponse<Http::STATUS_OK, TickbuddyPublicLink, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, TickbuddyError, array{}>
	 *
	 * 200: Link renamed
	 * 400: Invalid name
	 * 404: Link not found or revoked
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/public-links/{id}', requirements: ['id' => '\d+'])]
	public function update(int $id, string $name = ''): DataResponse {
		try {
			return new DataResponse($this->serializeLink($this->links->rename($this->userId, $id, $name)));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Public link not found', 'reason' => ErrorReason::PUBLIC_LINK_NOT_FOUND], Http::STATUS_NOT_FOUND);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Revoke a public link
	 *
	 * The link answers 404 from then on. Ticks it made are kept.
	 *
	 * @param int $id Link ID
	 * @return DataResponse<Http::STATUS_NO_CONTENT, null, array{}>|DataResponse<Http::STATUS_NOT_FOUND, TickbuddyError, array{}>
	 *
	 * 204: Link revoked, also when it already was
	 * 404: Link not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/public-links/{id}', requirements: ['id' => '\d+'])]
	public function destroy(int $id): DataResponse {
		try {
			$this->links->revoke($this->userId, $id);
			return new DataResponse(null, Http::STATUS_NO_CONTENT);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Public link not found', 'reason' => ErrorReason::PUBLIC_LINK_NOT_FOUND], Http::STATUS_NOT_FOUND);
		}
	}
}
