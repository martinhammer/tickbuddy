<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\Db\Track;
use OCP\IRequest;

/**
 * Whether the client asked, with `Prefer: handling=strict` (RFC 7240), to be refused
 * rather than quietly ignored when it writes to a read-only track. Until every client
 * has moved over, only clients that ask get 423; the rest keep the old answers.
 */
class StrictHandling {
	public function __construct(
		private IRequest $request,
	) {
	}

	public function requested(): bool {
		return self::prefersStrict($this->request->getHeader('Prefer'));
	}

	/**
	 * Refuse a write to an archived track if the client asked for strict handling;
	 * otherwise return, and the caller carries on as before.
	 *
	 * @throws ReadOnlyTrackException
	 */
	public function refuseArchived(Track $track): void {
		if ($this->requested()) {
			throw new ReadOnlyTrackException($track->getName() . ' is archived, so nothing was changed', ErrorReason::ARCHIVED);
		}
	}

	/**
	 * Whether a Prefer header value holds handling=strict. Names compare without
	 * case, values with it, and only the first `handling` counts (RFC 7240 §2).
	 *
	 * Splitting on commas ignores quoted strings: a quoted comma could only split
	 * another preference's value, never make a false handling=strict.
	 *
	 * @internal Public for the tests
	 */
	public static function prefersStrict(string $prefer): bool {
		foreach (explode(',', $prefer) as $preference) {
			// Parameters after ';' don't matter here
			$pair = explode('=', explode(';', $preference, 2)[0], 2);
			if (strcasecmp(trim($pair[0]), 'handling') === 0) {
				return trim(trim($pair[1] ?? ''), '"') === 'strict';
			}
		}
		return false;
	}
}
