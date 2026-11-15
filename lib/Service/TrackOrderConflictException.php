<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use Exception;

/**
 * Reordering lost the race to other track writes every time it tried. The
 * message is the user-facing text.
 */
class TrackOrderConflictException extends Exception {
}
