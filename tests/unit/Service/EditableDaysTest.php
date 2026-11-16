<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Service\EditableDays;
use PHPUnit\Framework\TestCase;

final class EditableDaysTest extends TestCase {
	private const TODAY = '2026-11-16';

	public static function settingsProvider(): array {
		// The setting, its oldest allowed day, and the day before it (null: no limit)
		return [
			'Today' => ['activeDay', '2026-11-16', '2026-11-15'],
			'+1 day' => ['activeAndPrevious', '2026-11-15', '2026-11-14'],
			'1 week' => ['oneWeek', '2026-11-10', '2026-11-09'],
			'All' => ['allDays', '1970-01-01', null],
		];
	}

	/**
	 * @dataProvider settingsProvider
	 */
	public function testAllowsTodayBackToItsLimit(string $setting, string $oldest, ?string $tooOld): void {
		$this->assertTrue(EditableDays::allows(self::TODAY, self::TODAY, $setting));
		$this->assertTrue(EditableDays::allows($oldest, self::TODAY, $setting));
		if ($tooOld !== null) {
			$this->assertFalse(EditableDays::allows($tooOld, self::TODAY, $setting));
		}
	}

	/**
	 * @dataProvider settingsProvider
	 */
	public function testNeverAllowsTomorrow(string $setting): void {
		$this->assertFalse(EditableDays::allows('2026-11-17', self::TODAY, $setting));
	}

	public function testCountsAcrossMonthsAndLeapDays(): void {
		$this->assertTrue(EditableDays::allows('2028-02-29', '2028-03-01', 'activeAndPrevious'));
		$this->assertTrue(EditableDays::allows('2026-12-26', '2027-01-01', 'oneWeek'));
		$this->assertFalse(EditableDays::allows('2026-12-25', '2027-01-01', 'oneWeek'));
	}

	public function testRefusesAnUnknownSettingOrDate(): void {
		$this->assertFalse(EditableDays::allows(self::TODAY, self::TODAY, 'always'));
		$this->assertFalse(EditableDays::allows('2026-02-30', self::TODAY, 'allDays'));
	}

	/** The web's list (src/editableDays.ts) */
	public function testValuesMatchTheWeb(): void {
		$web = (string)file_get_contents(__DIR__ . '/../../../src/editableDays.ts');
		preg_match('/EDITABLE_DAYS: EditableDays\\[\\] = \\[([^\\]]*)\\]/', $web, $m);
		$this->assertSame(EditableDays::VALUES, array_map(fn (string $v) => trim($v, " '"), explode(',', $m[1])));
		foreach (EditableDays::LABELS as $value => $label) {
			$this->assertStringContainsString("$value: '$label'", $web);
		}
	}
}
