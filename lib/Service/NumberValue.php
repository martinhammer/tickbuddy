<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

/**
 * A Number track's values. Stored in the ticks' INTEGER `value` as whole
 * thousandths (10.4 is 10400, -1.5 is -1500), so the limit is the column's
 * range divided by 1000. All arithmetic here works on digit strings and
 * integers, never on floats: a float only appears in {@see toWire}, for JSON,
 * where at most 10 significant digits always survive a double unchanged.
 */
final class NumberValue {
	/** The highest number of thousandths: the INTEGER column's range */
	public const MAX_MILLI = 2147483647;
	public const NOT_A_NUMBER = 'Number tracks take a number, such as 10.4';
	public const OUT_OF_RANGE = 'Numbers must be between -2147483.647 and 2147483.647';
	/** More whole digits than this is beyond the limit, whatever they are */
	private const MAX_WHOLE_DIGITS = 7;

	/**
	 * A value sent to set() or a public link, as thousandths rounded to
	 * `$decimalPlaces` half away from zero.
	 *
	 * A string must be `-?\d+(\.\d+)?` once trimmed: a point, no grouping, no
	 * `+`, no exponent. A JSON float is first written back as its shortest
	 * decimal, so 10.4 reads as "10.4", not as the double nearest to it.
	 *
	 * @param int $decimalPlaces 0 to 3
	 * @throws InvalidValueException if it isn't a number, or is beyond the limit
	 */
	public static function parse(mixed $value, int $decimalPlaces): int {
		[$negative, $whole, $fraction] = self::digits($value) ?? throw new InvalidValueException(self::NOT_A_NUMBER);
		if (strlen($whole) > self::MAX_WHOLE_DIGITS) {
			throw new InvalidValueException(self::OUT_OF_RANGE);
		}
		$scale = 10 ** (3 - $decimalPlaces);
		$fraction = str_pad($fraction, $decimalPlaces + 1, '0');
		$kept = substr($fraction, 0, $decimalPlaces);
		$milli = (int)$whole * 1000 + ($kept === '' ? 0 : (int)$kept * $scale);
		// Half away from zero: the sign is applied after rounding the magnitude
		if ($fraction[$decimalPlaces] >= '5') {
			$milli += $scale;
		}
		if ($milli > self::MAX_MILLI) {
			throw new InvalidValueException(self::OUT_OF_RANGE);
		}
		return $negative ? -$milli : $milli;
	}

	/**
	 * A Number value from a backup, as thousandths, taken as it was stored:
	 * a JSON number with up to 3 decimals within the limit, not rounded.
	 * Null for anything else, a string included.
	 */
	public static function parseStored(mixed $value): ?int {
		if (!is_int($value) && !is_float($value)) {
			return null;
		}
		// Below a thousandth, which digits() reads as 0, has more than 3 decimals
		if (is_float($value) && $value !== 0.0 && abs($value) < 0.001) {
			return null;
		}
		$digits = self::digits($value);
		if ($digits === null) {
			return null;
		}
		[$negative, $whole, $fraction] = $digits;
		$fraction = rtrim($fraction, '0');
		if (strlen($fraction) > 3 || strlen($whole) > self::MAX_WHOLE_DIGITS) {
			return null;
		}
		$milli = (int)$whole * 1000 + (int)str_pad($fraction, 3, '0');
		if ($milli > self::MAX_MILLI) {
			return null;
		}
		return $negative ? -$milli : $milli;
	}

	/**
	 * Stored thousandths as the API writes them: an integer when the value is
	 * whole (72), else a float holding exactly its decimal (10.4, -1.5).
	 */
	public static function toWire(int $milli): int|float {
		if ($milli % 1000 === 0) {
			return intdiv($milli, 1000);
		}
		$magnitude = abs($milli);
		$text = intdiv($magnitude, 1000) . '.' . rtrim(str_pad((string)($magnitude % 1000), 3, '0', STR_PAD_LEFT), '0');
		return (float)(($milli < 0 ? '-' : '') . $text);
	}

	/**
	 * A stored tick value as the API writes it for a track of `$type`: a
	 * Number's thousandths as {@see toWire}, any other type's value as it is.
	 */
	public static function forWire(int $stored, string $type): int|float {
		return $type === 'number' ? self::toWire($stored) : $stored;
	}

	/**
	 * The sign, the whole digits without leading zeros ('' for 0) and the
	 * decimal digits of a number, or null when it isn't one.
	 *
	 * @return ?array{0: bool, 1: string, 2: string}
	 */
	private static function digits(mixed $value): ?array {
		if (is_int($value)) {
			$text = (string)$value;
		} elseif (is_float($value)) {
			if (!is_finite($value)) {
				return null;
			}
			// The shortest decimal that reads back as this double (serialize_precision -1)
			$text = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
			if (preg_match('/^(-?)(\d+)(?:\.(\d+))?[eE]([-+]?\d+)$/D', $text, $m) === 1) {
				// Written with an exponent only below 0.0001 or from 1e15: the
				// first reads as 0 at three decimals, the second is beyond the limit
				return (int)$m[4] < 0 ? [false, '', ''] : [false, str_repeat('9', self::MAX_WHOLE_DIGITS + 1), ''];
			}
		} elseif (is_string($value)) {
			$text = trim($value);
		} else {
			return null;
		}
		if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $text, $m) !== 1) {
			return null;
		}
		return [$m[1] === '-', ltrim($m[2], '0'), $m[3] ?? ''];
	}
}
