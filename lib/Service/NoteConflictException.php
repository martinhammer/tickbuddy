<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use Exception;

/**
 * Appending to a note lost the race to other writers every time it tried. The
 * message is the user-facing text.
 */
class NoteConflictException extends Exception {
}
