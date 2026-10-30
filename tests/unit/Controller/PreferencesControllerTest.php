<?php

declare(strict_types=1);

namespace Controller;

use OCA\Tickbuddy\Controller\PreferencesController;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class PreferencesControllerTest extends TestCase {
	private const STARTING = [
		'journalLayoutDesktop' => 'daysDown',
		'journalLayoutMobile' => 'daysDown',
		'showPrivateDesktop' => false,
		'showPrivateMobile' => false,
		'editableDaysDesktop' => 'allDays',
		'editableDaysMobile' => 'activeDay',
	];

	/** @var array<string, string|bool> user config of the test user, by key */
	private array $stored = [];

	private function controller(): PreferencesController {
		$config = $this->createMock(IUserConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $user, string $app, string $key, string $default = '') => (string)($this->stored[$key] ?? $default));
		$config->method('getValueBool')->willReturnCallback(
			fn (string $user, string $app, string $key, bool $default = false) => (bool)($this->stored[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(function (string $user, string $app, string $key, string $value): bool {
			$this->assertSame('tickbuddy', $app);
			$this->stored[$key] = $value;
			return true;
		});
		$config->method('setValueBool')->willReturnCallback(function (string $user, string $app, string $key, bool $value): bool {
			$this->assertSame('tickbuddy', $app);
			$this->stored[$key] = $value;
			return true;
		});
		return new PreferencesController('tickbuddy', $this->createMock(IRequest::class), $config, 'testuser');
	}

	public function testNothingStoredAnswersTheStartingValues(): void {
		$response = $this->controller()->index();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::STARTING, $response->getData());
	}

	public function testStoredValuesAreAnswered(): void {
		$this->stored = [
			'journal_layout_desktop' => 'tracksDown',
			'show_private_mobile' => true,
			'editable_days_desktop' => 'oneWeek',
		];
		$this->assertSame(array_replace(self::STARTING, [
			'journalLayoutDesktop' => 'tracksDown',
			'showPrivateMobile' => true,
			'editableDaysDesktop' => 'oneWeek',
		]), $this->controller()->index()->getData());
	}

	public function testAnInvalidStoredValueAnswersTheStartingValue(): void {
		$this->stored = ['journal_layout_mobile' => 'sideways', 'editable_days_mobile' => 'ALL_DAYS'];
		$this->assertSame(self::STARTING, $this->controller()->index()->getData());
	}

	public function testOneFieldSavesOnlyItsKey(): void {
		$response = $this->controller()->update(editableDaysMobile: 'activeAndPrevious');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['editable_days_mobile' => 'activeAndPrevious'], $this->stored);
		$this->assertSame(array_replace(self::STARTING, ['editableDaysMobile' => 'activeAndPrevious']), $response->getData());
	}

	public static function badValues(): array {
		return [
			'journalLayoutDesktop' => [['journalLayoutDesktop' => 'x']],
			'journalLayoutMobile' => [['journalLayoutMobile' => 'DAYS_DOWN']],
			'editableDaysDesktop' => [['editableDaysDesktop' => '']],
			'editableDaysMobile' => [['editableDaysMobile' => 'x']],
		];
	}

	/**
	 * @dataProvider badValues
	 */
	public function testAnUnknownValueSavesNothing(array $sent): void {
		$field = array_key_first($sent);
		// A valid field in the same request isn't saved either
		$other = $field === 'journalLayoutDesktop' ? ['editableDaysMobile' => 'oneWeek'] : ['journalLayoutDesktop' => 'tracksDown'];
		$response = $this->controller()->update(...$sent, ...$other, showPrivateDesktop: true);
		$this->assertSame(400, $response->getStatus());
		$this->assertSame([
			'message' => sprintf('Unknown value "%s" for %s', $sent[$field], $field),
			'reason' => 'invalidValue',
		], $response->getData());
		$this->assertSame([], $this->stored);
	}

	public function testShowPrivateStoresTheBool(): void {
		// Nextcloud casts the form values true and 1 to true, false to false
		$controller = $this->controller();
		$controller->update(showPrivateMobile: true);
		$this->assertSame(['show_private_mobile' => true], $this->stored);
		$this->assertTrue($controller->index()->getData()['showPrivateMobile']);
		$controller->update(showPrivateMobile: false);
		$this->assertSame(['show_private_mobile' => false], $this->stored);
		$this->assertFalse($controller->index()->getData()['showPrivateMobile']);
	}

	public function testDefaultViewAloneChangesNothing(): void {
		// Nextcloud drops a parameter the method doesn't take, so `defaultView` arrives as nothing at all
		$response = $this->controller()->update();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame([], $this->stored);
		$this->assertSame(self::STARTING, $response->getData());
	}
}
