<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
use OCA\Tickbuddy\Service\StrictHandling;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class StrictHandlingTest extends TestCase {
	public static function strictHeaders(): array {
		return [
			'plain' => ['handling=strict'],
			'name in capitals' => ['HANDLING=strict'],
			'after another preference' => ['return=minimal, handling=strict'],
			'spaces around =' => ['handling = strict'],
			'quoted' => ['handling="strict"'],
			'with a parameter' => ['handling=strict; foo=bar'],
			'only the first counts' => ['handling=strict, handling=lenient'],
		];
	}

	/**
	 * @dataProvider strictHeaders
	 */
	public function testPrefersStrict(string $prefer): void {
		$this->assertTrue(StrictHandling::prefersStrict($prefer));
	}

	public static function otherHeaders(): array {
		return [
			'missing' => [''],
			'value in capitals' => ['handling=Strict'],
			'lenient' => ['handling=lenient'],
			'only the first counts' => ['handling=lenient, handling=strict'],
			'no value' => ['handling'],
			'another preference' => ['return=minimal'],
			'another name' => ['xhandling=strict'],
		];
	}

	/**
	 * @dataProvider otherHeaders
	 */
	public function testDoesNotPreferStrict(string $prefer): void {
		$this->assertFalse(StrictHandling::prefersStrict($prefer));
	}

	private function handling(string $prefer): StrictHandling {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Prefer')->willReturn($prefer);
		return new StrictHandling($request);
	}

	private function archivedTrack(): Track {
		$track = new Track();
		$track->setName('Exercise');
		$track->setArchivedAt('2026-08-14');
		return $track;
	}

	public function testRefuseArchivedThrowsWhenAsked(): void {
		try {
			$this->handling('handling=strict')->refuseArchived($this->archivedTrack());
			$this->fail('Expected ReadOnlyTrackException');
		} catch (ReadOnlyTrackException $e) {
			$this->assertSame('Exercise is archived, so nothing was changed', $e->getMessage());
			$this->assertSame('archived', $e->getReason());
		}
	}

	public function testRefuseArchivedReturnsWhenNotAsked(): void {
		$this->handling('')->refuseArchived($this->archivedTrack());
		$this->handling('handling=lenient')->refuseArchived($this->archivedTrack());
		$this->addToAssertionCount(1);
	}

	public function testRequested(): void {
		$this->assertTrue($this->handling('return=minimal, handling=strict')->requested());
		$this->assertFalse($this->handling('')->requested());
	}
}
