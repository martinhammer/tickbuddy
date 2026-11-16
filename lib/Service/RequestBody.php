<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCP\IRequest;

/**
 * The body of a POST sent as plain text, which a public link reads as text to
 * append to the day's note. Nextcloud reads form and JSON bodies as parameters,
 * but leaves any other body unread.
 */
class RequestBody {
	/** Read at most this much: far above a note's limit, which the note rules check */
	private const MAX_BYTES = 65536;

	public function __construct(
		private IRequest $request,
	) {
	}

	/**
	 * The body of a POST with `Content-Type: text/plain` (any charset); null for
	 * any other request, and for an empty body.
	 *
	 * @throws InvalidValueException if the body is over 64 KiB
	 */
	public function plainText(): ?string {
		if ($this->request->getMethod() !== 'POST'
			|| preg_match('#^\s*text/plain\s*(;|$)#i', $this->request->getHeader('Content-Type')) !== 1) {
			return null;
		}
		$body = $this->read();
		if (strlen($body) > self::MAX_BYTES) {
			throw new InvalidValueException('Notes can be at most ' . NoteService::MAX_LENGTH . ' characters');
		}
		return $body === '' ? null : $body;
	}

	/**
	 * Up to one byte more than MAX_BYTES of the raw body.
	 */
	protected function read(): string {
		$stream = fopen('php://input', 'rb');
		if ($stream === false) {
			return '';
		}
		$body = stream_get_contents($stream, self::MAX_BYTES + 1);
		fclose($stream);
		return $body === false ? '' : $body;
	}
}
