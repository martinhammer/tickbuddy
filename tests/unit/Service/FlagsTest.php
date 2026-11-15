<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Service\Flags;
use OCA\Tickbuddy\Service\InvalidValueException;
use PHPUnit\Framework\TestCase;

final class FlagsTest extends TestCase {
	public static function spellingProvider(): array {
		return [
			'"true"' => ['true', true],
			'"False"' => ['False', false],
			'" on "' => [' on ', true],
			'"off"' => ['off', false],
			'"yes"' => ['yes', true],
			'"no"' => ['no', false],
			'"1"' => ['1', true],
			'"0"' => ['0', false],
			'JSON true' => [true, true],
			'JSON false' => [false, false],
			'integer 1' => [1, true],
			'integer 0' => [0, false],
			'empty' => ['', null],
			'"2"' => ['2', null],
			'"maybe"' => ['maybe', null],
			'integer 2' => [2, null],
			'float 1.0' => [1.0, null],
		];
	}

	/**
	 * @dataProvider spellingProvider
	 */
	public function testParse(string|int|float|bool $value, ?bool $expected): void {
		$this->assertSame($expected, Flags::parse($value, false));
	}

	public function testNotSentIsTheDefault(): void {
		$this->assertFalse(Flags::parse(null, false));
		$this->assertTrue(Flags::parse(null, true));
		$this->assertTrue(Flags::read('includeRuns', null, true));
		$this->assertNull(Flags::readOptional('private', null));
	}

	public function testReadOptionalReadsWhatWasSent(): void {
		$this->assertFalse(Flags::readOptional('private', 'no'));
		$this->assertTrue(Flags::readOptional('private', 'on'));
	}

	public static function refusalProvider(): array {
		return [
			'read' => [fn () => Flags::read('includeArchived', 'maybe', false)],
			'readOptional, empty' => [fn () => Flags::readOptional('includeArchived', '')],
			'check' => [fn () => Flags::check('includeArchived', null)],
		];
	}

	/**
	 * @dataProvider refusalProvider
	 */
	public function testAnUnreadableFlagIsRefusedByName(\Closure $read): void {
		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('includeArchived must be true or false');
		$read();
	}
}
