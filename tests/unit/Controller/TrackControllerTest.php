<?php

declare(strict_types=1);

namespace Controller;

use OCA\Tickbuddy\Controller\TrackController;
use OCA\Tickbuddy\Db\IdempotencyKeyMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCA\Tickbuddy\Service\PublicLinkService;
use OCA\Tickbuddy\Service\TrackService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `publicLinkCount` on every answer that holds tracks.
 */
final class TrackControllerTest extends TestCase {
	private TrackService $tracks;
	private PublicLinkService $links;

	protected function setUp(): void {
		$this->tracks = $this->createMock(TrackService::class);
		$this->links = $this->createMock(PublicLinkService::class);
	}

	private function controller(): TrackController {
		$request = $this->createMock(IRequest::class);
		$idempotency = new IdempotencyService($request, $this->createMock(IdempotencyKeyMapper::class), $this->createMock(ITimeFactory::class), $this->createMock(LoggerInterface::class));
		return new TrackController('tickbuddy', $request, $this->tracks, $this->links, $idempotency, 'testuser', $this->createMock(LoggerInterface::class));
	}

	private function makeTrack(int $id, ?string $archivedAt = null): Track {
		$track = new Track();
		$track->setId($id);
		$track->setArchivedAt($archivedAt);
		return $track;
	}

	public function testListCountsEachTracksLinksInOneQuery(): void {
		$this->tracks->method('findAll')->willReturn([$this->makeTrack(1), $this->makeTrack(2), $this->makeTrack(3, '2026-11-01')]);
		$this->links->expects($this->once())->method('countsByTrack')->with('testuser')->willReturn([1 => 2]);

		$data = $this->controller()->index('true')->getData();

		$this->assertSame([2, 0, 0], array_column($data, 'publicLinkCount'));
	}

	public function testANewTrackHasNoLinks(): void {
		$this->tracks->method('create')->willReturn($this->makeTrack(4));
		$this->links->expects($this->never())->method($this->anything());

		$this->assertSame(0, $this->controller()->create('Run', 'boolean')->getData()['publicLinkCount']);
	}

	public function testUpdateCountsTheTracksLinks(): void {
		$this->tracks->method('update')->willReturn($this->makeTrack(1));
		$this->links->method('countForTrack')->with(1)->willReturn(1);

		$this->assertSame(1, $this->controller()->update(1, 'Run')->getData()['publicLinkCount']);
	}

	public function testReorderCounts(): void {
		$this->tracks->method('reorder')->willReturn([$this->makeTrack(2), $this->makeTrack(1)]);
		$this->links->method('countsByTrack')->willReturn([1 => 3]);

		$this->assertSame([0, 3], array_column($this->controller()->reorder([2, 1])->getData(), 'publicLinkCount'));
	}
}
