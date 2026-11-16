<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\Db\Note;
use OCA\Tickbuddy\Db\PublicLink;
use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\EditableDays;
use OCA\Tickbuddy\Service\ErrorReason;
use OCA\Tickbuddy\Service\Flags;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\NoteConflictException;
use OCA\Tickbuddy\Service\NoteService;
use OCA\Tickbuddy\Service\PublicLinkService;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
use OCA\Tickbuddy\Service\RequestBody;
use OCA\Tickbuddy\Service\TickService;
use OCA\Tickbuddy\Service\TrackService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The address a public link holds: a script, a home automation or anything
 * that can open a web address ticks or reads one track with it, without
 * signing in. The link's owner is the user, whoever calls: there is no
 * `$userId` here, as Nextcloud would inject the caller's session on a public
 * page (or null).
 *
 * @psalm-import-type TickbuddyError from ResponseDefinitions
 * @psalm-import-type TickbuddyNote from ResponseDefinitions
 * @psalm-import-type TickbuddyPingView from ResponseDefinitions
 * @psalm-import-type TickbuddyTick from ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class PingController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private PublicLinkService $links,
		private TrackService $trackService,
		private TickService $tickService,
		private NoteService $noteService,
		private IdempotencyService $idempotency,
		private RequestBody $body,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * JSON unless the caller asks for XML (`?format=xml`, `Accept: application/xml`).
	 * OCSController's default is XML, which curl (`Accept: *\/*`) and a browser
	 * bookmark would get, as neither asks for a format.
	 *
	 * @param mixed $response
	 * @param string $format
	 * @return \OCP\AppFramework\Http\Response
	 */
	public function buildResponse($response, $format = 'json') {
		return parent::buildResponse($response, $format);
	}

	/**
	 * Call a public link
	 *
	 * No `OCS-APIRequest`, no `Authorization` and no CSRF token: the token in
	 * the address is the credential. GET, POST and HEAD all do what the link is
	 * for. Answers JSON unless asked for XML.
	 *
	 * A Tick only link ticks a yes/no day, or adds 1 to a counter, or with
	 * `value` sets the day to it; `appendNote`, or a POST body sent as
	 * `text/plain`, adds a line to the day's note (a line already there is
	 * skipped). A View only link answers the day's tick, or a range's, and with
	 * `includeNotes` the notes. The day is today in the owner's time zone unless
	 * `date` (or `from` and `to`) says otherwise, and must be one of the link's
	 * allowed days, never after today.
	 *
	 * Tick calls honour `Idempotency-Key` (a UUID) as the API's writes do.
	 *
	 * @param string $token The link's token
	 * @param string|int|float|bool|null $value Tick only. Yes/no: true or false (also 1/0, on/off, yes/no). Counter: the day's new value, a whole number from 0 to 2147483647 (0 clears it)
	 * @param ?string $date The day (YYYY-MM-DD); today in the owner's time zone if missing
	 * @param string|int|float|bool|null $appendNote Tick only. Text to add to the day's note on a new line; not with a text body
	 * @param ?string $from View only. First day of a range (YYYY-MM-DD), with `to`; not with `date`
	 * @param ?string $to View only. Last day of a range (YYYY-MM-DD), with `from`
	 * @param string|int|float|bool|null $includeNotes View only. Also answer the notes: true or false (also 1/0, on/off, yes/no)
	 * @return DataResponse<Http::STATUS_OK, array{value: int, note?: string}|TickbuddyPingView, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 *
	 * 200: Tick only: the day's value (1 or 0 for yes/no), and `note`, the whole stored note, when text was appended. View only: the ticks (sparse, newest first), and the notes with `includeNotes`. With `Idempotency-Key`: a repeat of an earlier request answers as that request did, and changes nothing
	 * 400: A parameter is invalid, the other kind of link's parameters were sent, the track's type can't be ticked by a link yet, or `Idempotency-Key` is not a UUID; nothing done
	 * 403: A day is outside the link's allowed days; nothing done
	 * 404: Unknown or revoked link, or its owner can't sign in
	 * 409: The tick was written, but the note kept changing while appending, or a request with the same `Idempotency-Key` is still being handled
	 * 422: This `Idempotency-Key` was used for another request; nothing written
	 * 423: The track was archived during the call; only for requests sent with Prefer: handling=strict
	 */
	// Unions for the framework, as on the API: typed bool or int, Nextcloud would
	// cast "no" to true and "true" to 0. The POST route needs its own postfix:
	// two routes on one method would otherwise share a name, the second
	// replacing the first.
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'tickbuddyPublicLink')]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/ping/{token}')]
	#[ApiRoute(verb: 'POST', url: '/ping/{token}', postfix: 'post')]
	public function call(
		string $token,
		string|int|float|bool|null $value = null,
		?string $date = null,
		string|int|float|bool|null $appendNote = null,
		?string $from = null,
		?string $to = null,
		string|int|float|bool|null $includeNotes = null,
	): DataResponse {
		$link = $this->links->findByToken($token);
		if ($link === null) {
			// Only a token that never was a link counts as a guess: a script still
			// calling a revoked link mustn't slow down the others called from its server
			$response = $this->notFound();
			$response->throttle();
			return $response;
		}
		if (!$this->links->isLive($link)) {
			return $this->notFound();
		}
		// Every call that reaches a live link counts, whatever it answers
		$this->links->touch($link);

		try {
			$body = $this->body->plainText();
		} catch (InvalidValueException $e) {
			return $this->invalid($e->getMessage());
		}

		return match ($link->getPermission()) {
			PublicLink::PERMISSION_TICK => $this->tick($link, $value, $date, $appendNote, $body, $from !== null || $to !== null || $includeNotes !== null),
			PublicLink::PERMISSION_VIEW => $this->view($link, $date, $from, $to, $includeNotes, $value !== null || $appendNote !== null || $body !== null),
			default => $this->notFound(),
		};
	}

	/**
	 * @return DataResponse<Http::STATUS_OK, array{value: int, note?: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 */
	private function tick(PublicLink $link, string|int|float|bool|null $value, ?string $date, string|int|float|bool|null $appendNote, ?string $body, bool $viewParameters): DataResponse {
		$owner = $link->getUserId();
		$text = $body ?? $appendNote;
		// Keyed to the owner, as keys are per user. The draft is written for POST,
		// but a GET or HEAD here writes just the same, so any method honours it.
		return $this->idempotency->run($owner, 'ping.tick', [$link->getId(), $value, $date, $text], function () use ($link, $owner, $value, $date, $appendNote, $body, $text, $viewParameters): DataResponse {
			if ($viewParameters) {
				return $this->invalid("Tick only links don't read: leave out from, to and includeNotes");
			}
			if ($body !== null && $appendNote !== null) {
				return $this->invalid('Send appendNote or a text body, not both');
			}
			$today = $this->links->todayFor($owner);
			$day = $date ?? $today;
			if (!TrackService::isValidDate($day)) {
				return $this->invalid('Date must be a date as YYYY-MM-DD');
			}
			if (!EditableDays::allows($day, $today, $link->getAllowedDays())) {
				return $this->dayNotAllowed($link, $day);
			}
			try {
				$track = $this->trackService->find($link->getTrackId(), $owner);
				// A line already in the note is always skipped (no allowDuplicateNote)
				return new DataResponse(match ($track->getType()) {
					'boolean' => $this->tickService->set($owner, $track->getId(), $day, $value ?? true, null, $text, false),
					'counter' => $value === null
						? $this->tickService->increment($owner, $track->getId(), $day, 1, null, $text, false)
						: $this->tickService->set($owner, $track->getId(), $day, $value, null, $text, false),
					default => throw new InvalidTrackTypeException("Public links can't tick tracks of this type yet"),
				});
			} catch (DoesNotExistException) {
				return $this->notFound();
			} catch (InvalidTrackTypeException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::WRONG_TRACK_TYPE], Http::STATUS_BAD_REQUEST);
			} catch (InvalidValueException $e) {
				return $this->invalid($e->getMessage());
			} catch (NoteConflictException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::NOTE_CONFLICT], Http::STATUS_CONFLICT);
			} catch (ReadOnlyTrackException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_LOCKED);
			}
		});
	}

	/**
	 * @return DataResponse<Http::STATUS_OK, TickbuddyPingView, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, TickbuddyError, array{}>
	 */
	private function view(PublicLink $link, ?string $date, ?string $from, ?string $to, string|int|float|bool|null $includeNotes, bool $tickParameters): DataResponse {
		if ($tickParameters) {
			return $this->invalid("View only links don't tick: leave out value, appendNote and the body");
		}
		if ($date !== null && ($from !== null || $to !== null)) {
			return $this->invalid('Send date, or from and to, not both');
		}
		$owner = $link->getUserId();
		$today = $this->links->todayFor($owner);
		try {
			if ($from !== null || $to !== null) {
				$from ??= '';
				$to ??= '';
				TrackService::checkDateRange($from, $to);
			} else {
				$from = $to = $date ?? $today;
				if (!TrackService::isValidDate($from)) {
					return $this->invalid('Date must be a date as YYYY-MM-DD');
				}
			}
			$withNotes = Flags::read('includeNotes', $includeNotes, false);
		} catch (InvalidValueException $e) {
			return $this->invalid($e->getMessage());
		}
		// Allowed days are a run of days ending today, so both ends decide
		if (!EditableDays::allows($from, $today, $link->getAllowedDays()) || !EditableDays::allows($to, $today, $link->getAllowedDays())) {
			return $from === $to ? $this->dayNotAllowed($link, $from) : $this->dayNotAllowed($link, $from, $to);
		}
		try {
			$ticks = $this->tickService->findByDateRange($owner, $from, $to, $link->getTrackId());
			$data = ['ticks' => array_values(array_map(fn (Tick $t) => self::serializeTick($t), $ticks))];
			if ($withNotes) {
				$notes = $this->noteService->findByDateRange($owner, $from, $to, $link->getTrackId());
				$data['notes'] = array_values(array_map(fn (Note $n) => self::serializeNote($n), $notes));
			}
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (InvalidValueException $e) {
			return $this->invalid($e->getMessage());
		}
		return new DataResponse($data);
	}

	/**
	 * @return TickbuddyTick
	 */
	private static function serializeTick(Tick $tick): array {
		return [
			'id' => $tick->getId(),
			'trackId' => $tick->getTrackId(),
			'date' => $tick->getDate(),
			'value' => $tick->getValue(),
		];
	}

	/**
	 * @return TickbuddyNote
	 */
	private static function serializeNote(Note $note): array {
		return [
			'trackId' => $note->getTrackId(),
			'date' => $note->getDate(),
			'text' => $note->getText(),
		];
	}

	/**
	 * @return DataResponse<Http::STATUS_NOT_FOUND, TickbuddyError, array{}>
	 */
	private function notFound(): DataResponse {
		return new DataResponse(['message' => 'Public link not found', 'reason' => ErrorReason::PUBLIC_LINK_NOT_FOUND], Http::STATUS_NOT_FOUND);
	}

	/**
	 * @return DataResponse<Http::STATUS_BAD_REQUEST, TickbuddyError, array{}>
	 */
	private function invalid(string $message): DataResponse {
		return new DataResponse(['message' => $message, 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @return DataResponse<Http::STATUS_FORBIDDEN, TickbuddyError, array{}>
	 */
	private function dayNotAllowed(PublicLink $link, string $from, ?string $to = null): DataResponse {
		$allowed = "This link's allowed days (" . (EditableDays::LABELS[$link->getAllowedDays()] ?? $link->getAllowedDays()) . ") don't include ";
		$message = $to === null ? $allowed . $from : $allowed . 'every day from ' . $from . ' to ' . $to;
		return new DataResponse(['message' => $message, 'reason' => ErrorReason::DAY_NOT_ALLOWED], Http::STATUS_FORBIDDEN);
	}
}
