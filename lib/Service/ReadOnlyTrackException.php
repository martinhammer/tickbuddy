<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use Exception;

/**
 * A write to a track that can't change (archived; later, immutable), refused for a
 * client that sent `Prefer: handling=strict`. The controllers answer 423 Locked.
 * The message is the user-facing text.
 */
class ReadOnlyTrackException extends Exception {
	public function __construct(
		string $message,
		private string $reason,
	) {
		parent::__construct($message);
	}

	/**
	 * The `reason` for the answer, an {@see ErrorReason} value
	 */
	public function getReason(): string {
		return $this->reason;
	}
}
