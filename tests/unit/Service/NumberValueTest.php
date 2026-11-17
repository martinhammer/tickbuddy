<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\NumberValue;
use PHPUnit\Framework\TestCase;

final class NumberValueTest extends TestCase {
	public static function parseProvider(): array {
		return [
			'string' => ['10.4', 1, 10400],
			'JSON float' => [10.4, 1, 10400],
			'JSON integer' => [72, 1, 72000],
			'zero' => [0, 1, 0],
			'half up on 1' => ['10.45', 1, 10500],
			'half away from zero, negative' => ['-10.45', 1, -10500],
			'JSON float 10.45 on 1' => [10.45, 1, 10500],
			'0.05 on 1' => ['0.05', 1, 100],
			'down on 1' => ['21.449', 1, 21400],
			'21.456 on 1' => [21.456, 1, 21500],
			'on 0' => ['2.5', 0, 3000],
			'negative on 0' => ['-2.5', 0, -3000],
			'more than 3 decimals on 3' => ['1.2345', 3, 1235],
			'leading zeros and padding' => [' 007.50 ', 2, 7500],
			'tiny float' => [1e-7, 3, 0],
			'tiny negative float' => [-1e-7, 3, 0],
			'negative rounding to zero' => ['-0.04', 1, 0],
			'highest' => ['2147483.647', 3, 2147483647],
			'lowest' => ['-2147483.647', 3, -2147483647],
			'highest whole on 0' => ['2147483', 0, 2147483000],
		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse(mixed $value, int $decimalPlaces, int $expected): void {
		$this->assertSame($expected, NumberValue::parse($value, $decimalPlaces));
	}

	public static function notANumberProvider(): array {
		return [
			'comma' => ['10,4'],
			'plus' => ['+5'],
			'no whole digits' => ['.5'],
			'no decimals' => ['5.'],
			'exponent' => ['1e3'],
			'grouping' => ['1,204.50'],
			'empty' => [''],
			'word' => ['abc'],
			'JSON true' => [true],
			'list' => [[1]],
			'null' => [null],
			'infinity' => [INF],
			'NaN' => [NAN],
		];
	}

	/**
	 * @dataProvider notANumberProvider
	 */
	public function testNotANumber(mixed $value): void {
		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Number tracks take a number, such as 10.4');
		NumberValue::parse($value, 1);
	}

	public static function beyondProvider(): array {
		return [
			'just above' => ['2147483.648', 3],
			'rounding past it' => ['2147483.6475', 3],
			'rounding past it on 0' => ['2147483.5', 0],
			'just below' => ['-2147483.648', 3],
			'huge float' => [1e20, 1],
			'eight whole digits' => ['10000000', 0],
			'very long string' => [str_repeat('9', 40), 0],
		];
	}

	/**
	 * @dataProvider beyondProvider
	 */
	public function testBeyondTheLimit(mixed $value, int $decimalPlaces): void {
		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Numbers must be between -2147483.647 and 2147483.647');
		NumberValue::parse($value, $decimalPlaces);
	}

	public static function storedProvider(): array {
		return [
			'decimal' => [10.45, 10450],
			'whole' => [72, 72000],
			'negative' => [-1.5, -1500],
			'zero' => [0, 0],
			'three decimals' => [0.125, 125],
			'whole float' => [72.0, 72000],
			'four decimals' => [0.1255, null],
			'tiny' => [1e-7, null],
			'beyond' => [2147483.648, null],
			'string' => ['10.4', null],
			'bool' => [true, null],
			'null' => [null, null],
		];
	}

	/**
	 * @dataProvider storedProvider
	 */
	public function testParseStored(mixed $value, ?int $expected): void {
		$this->assertSame($expected, NumberValue::parseStored($value));
	}

	public function testToWire(): void {
		$this->assertSame(10.4, NumberValue::toWire(10400));
		$this->assertSame('10.4', json_encode(NumberValue::toWire(10400)));
		$this->assertSame(-1.5, NumberValue::toWire(-1500));
		$this->assertSame(72, NumberValue::toWire(72000));
		$this->assertSame(0, NumberValue::toWire(0));
		$this->assertSame('-0.005', json_encode(NumberValue::toWire(-5)));
		$this->assertSame('2147483.647', json_encode(NumberValue::toWire(2147483647)));
		$this->assertSame('-2147483.647', json_encode(NumberValue::toWire(-2147483647)));
	}

	public function testForWire(): void {
		$this->assertSame(10.45, NumberValue::forWire(10450, 'number'));
		$this->assertSame(10450, NumberValue::forWire(10450, 'counter'));
		$this->assertSame(2, NumberValue::forWire(2, 'choice'));
	}
}
