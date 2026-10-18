<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use Exception;

/**
 * A request parameter that isn't valid: a yes/no value, a delta or a date. The
 * message is the user-facing text.
 */
class InvalidValueException extends Exception {
}
