<?php

declare(strict_types=1);

namespace Tickbuddy;

use OCA\Tickbuddy\Capabilities;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase {
	private IAppManager $appManager;
	private Capabilities $capabilities;

	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->capabilities = new Capabilities($this->appManager);
	}

	public function testExposesAppVersion(): void {
		$this->appManager->method('getAppVersion')
			->with('tickbuddy')
			->willReturn('1.0.5');

		$capabilities = $this->capabilities->getCapabilities();

		$this->assertSame('1.0.5', $capabilities['tickbuddy']['version']);
	}

	public function testExposesApiVersion(): void {
		$this->appManager->method('getAppVersion')->willReturn('1.0.5');

		$capabilities = $this->capabilities->getCapabilities();

		$this->assertSame(Capabilities::API_VERSION, $capabilities['tickbuddy']['apiVersion']);
		$this->assertSame(1, $capabilities['tickbuddy']['apiVersion']);
	}

	public function testExposesFeatureFlags(): void {
		$this->appManager->method('getAppVersion')->willReturn('1.0.5');

		$features = $this->capabilities->getCapabilities()['tickbuddy']['features'];

		$this->assertTrue($features['import']);
		$this->assertTrue($features['export']);
		$this->assertTrue($features['counterTracks']);
		$this->assertTrue($features['privateTracks']);
		$this->assertTrue($features['tickBounds']);
		$this->assertTrue($features['archivedTracks']);
		$this->assertTrue($features['counterIncrement']);
		$this->assertTrue($features['booleanSet']);
		$this->assertTrue($features['stats']);
		$this->assertTrue($features['notes']);
		// Known gap, not yet implemented server-side.
		$this->assertFalse($features['syncDelta']);
	}
}
