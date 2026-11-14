<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

/**
 * The API's boolean flags (`includeArchived`, `private`, `allowDuplicate`…),
 * read as yes/no tick values are: what {@see TickService::parseYesNo} takes,
 * and nothing else, an empty value included.
 *
 * Controllers take a flag as a union, never as `bool`, in the signature and
 * in the docblock alike: Nextcloud's dispatcher casts a parameter typed
 * `bool` in either place with settype(), which makes "no" and "off" true.
 */
final class Flags {
	/**
	 * A flag as sent, `$default` when it wasn't sent; null when it can't be read.
	 */
	public static function parse(string|int|float|bool|null $value, bool $default): ?bool {
		return $value === null ? $default : TickService::parseYesNo($value);
	}

	/**
	 * A flag as sent, `$default` when it wasn't sent.
	 *
	 * @throws InvalidValueException if it can't be read
	 */
	public static function read(string $name, string|int|float|bool|null $value, bool $default): bool {
		return self::check($name, self::parse($value, $default));
	}

	/**
	 * A flag as sent, null when it wasn't sent ("leave it").
	 *
	 * @throws InvalidValueException if it can't be read
	 */
	public static function readOptional(string $name, string|int|float|bool|null $value): ?bool {
		return $value === null ? null : self::check($name, TickService::parseYesNo($value));
	}

	/**
	 * A flag {@see parse} read, for writes that need it read before they
	 * refuse it (an `Idempotency-Key` fingerprint holds the parsed value).
	 *
	 * @throws InvalidValueException if it couldn't be read
	 */
	public static function check(string $name, ?bool $parsed): bool {
		if ($parsed === null) {
			throw new InvalidValueException($name . ' must be true or false');
		}
		return $parsed;
	}
}
