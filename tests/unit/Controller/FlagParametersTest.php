<?php

declare(strict_types=1);

namespace Controller;

use OCA\Tickbuddy\Controller\ExportController;
use OCA\Tickbuddy\Controller\NoteController;
use OCA\Tickbuddy\Controller\StatsController;
use OCA\Tickbuddy\Controller\TickController;
use OCA\Tickbuddy\Controller\TrackController;
use OCA\Tickbuddy\Db\IdempotencyKey;
use OCA\Tickbuddy\Db\IdempotencyKeyMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Service\ExportService;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCA\Tickbuddy\Service\NoteService;
use OCA\Tickbuddy\Service\StatsService;
use OCA\Tickbuddy\Service\TickService;
use OCA\Tickbuddy\Service\TrackService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Boolean flags reach the services as read by Flags, on every endpoint that
 * takes one, and one that can't be read is refused before anything runs.
 * The parameters are unions, so these calls are what Nextcloud's dispatcher
 * passes: the form's text, or a JSON value, uncast.
 */
final class FlagParametersTest extends TestCase {
	private IRequest $request;
	private IdempotencyKeyMapper $idempotencyMapper;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->idempotencyMapper = $this->createMock(IdempotencyKeyMapper::class);
	}

	private function idempotency(): IdempotencyService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_792_000_000);
		return new IdempotencyService($this->request, $this->idempotencyMapper, $time, $this->createMock(LoggerInterface::class));
	}

	public static function readableProvider(): array {
		return [
			'"true"' => ['true', true],
			'"False"' => ['False', false],
			'" on "' => [' on ', true],
			'"0"' => ['0', false],
			'"no"' => ['no', false],
			'JSON true' => [true, true],
			'integer 1' => [1, true],
		];
	}

	public static function unreadableProvider(): array {
		return [
			'empty' => [''],
			'"2"' => ['2'],
			'"maybe"' => ['maybe'],
		];
	}

	private function assertRefused(DataResponse $response, string $name): void {
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => $name . ' must be true or false', 'reason' => 'invalidValue'], $response->getData());
	}

	// --- tracks ---

	private function tracks(TrackService $service): TrackController {
		return new TrackController('tickbuddy', $this->request, $service, $this->idempotency(), 'testuser', $this->createMock(LoggerInterface::class));
	}

	/**
	 * @dataProvider readableProvider
	 */
	public function testTrackListReadsIncludeArchived(string|int|bool $value, bool $expected): void {
		$service = $this->createMock(TrackService::class);
		$service->expects($this->once())->method('findAll')->with('testuser', $expected)->willReturn([]);

		$this->assertSame(200, $this->tracks($service)->index($value)->getStatus());
	}

	/**
	 * @dataProvider unreadableProvider
	 */
	public function testTrackListRefusesIncludeArchived(string $value): void {
		$service = $this->createMock(TrackService::class);
		$service->expects($this->never())->method('findAll');

		$this->assertRefused($this->tracks($service)->index($value), 'includeArchived');
	}

	/**
	 * @dataProvider readableProvider
	 */
	public function testTrackUpdateReadsPrivateAndArchived(string|int|bool $value, bool $expected): void {
		$track = new Track();
		$track->setId(1);
		$service = $this->createMock(TrackService::class);
		$service->expects($this->exactly(2))->method('update')
			->willReturnCallback(function (int $id, string $userId, ?string $name, ?int $sortOrder, ?bool $private, ?bool $archived) use ($expected, $track): Track {
				$this->assertSame($expected, $private ?? $archived);
				return $track;
			});

		$this->assertSame(200, $this->tracks($service)->update(1, private: $value)->getStatus());
		$this->assertSame(200, $this->tracks($service)->update(1, archived: $value)->getStatus());
	}

	public function testTrackUpdateLeavesFlagsNotSent(): void {
		$track = new Track();
		$service = $this->createMock(TrackService::class);
		$service->expects($this->once())->method('update')->with(1, 'testuser', 'Run', null, null, null, null)->willReturn($track);

		$this->tracks($service)->update(1, 'Run');
	}

	/**
	 * @dataProvider unreadableProvider
	 */
	public function testTrackUpdateRefusesPrivateAndArchivedAndChangesNothing(string $value): void {
		$service = $this->createMock(TrackService::class);
		$service->expects($this->never())->method('update');

		$this->assertRefused($this->tracks($service)->update(1, 'Run', private: $value), 'private');
		$this->assertRefused($this->tracks($service)->update(1, archived: $value), 'archived');
	}

	// --- stats and export ---

	/**
	 * @dataProvider readableProvider
	 */
	public function testStatsReadsItsFlags(string|int|bool $value, bool $expected): void {
		$service = $this->createMock(StatsService::class);
		$service->expects($this->once())->method('forUser')
			->with('testuser', '2026-11-14', null, $expected, !$expected)->willReturn([]);
		$controller = new StatsController('tickbuddy', $this->request, $service, 'testuser');

		$this->assertSame(200, $controller->index('2026-11-14', includeRuns: $value, includeArchived: !$expected ? 'yes' : 'no')->getStatus());
	}

	/**
	 * @dataProvider unreadableProvider
	 */
	public function testStatsRefusesItsFlags(string $value): void {
		$service = $this->createMock(StatsService::class);
		$service->expects($this->never())->method('forUser');
		$controller = new StatsController('tickbuddy', $this->request, $service, 'testuser');

		$this->assertRefused($controller->index('2026-11-14', includeRuns: $value), 'includeRuns');
		$this->assertRefused($controller->index('2026-11-14', includeArchived: $value), 'includeArchived');
	}

	/**
	 * @dataProvider readableProvider
	 */
	public function testExportReadsIncludePrivate(string|int|bool $value, bool $expected): void {
		$service = $this->createMock(ExportService::class);
		$service->expects($this->once())->method('export')->with('testuser', $expected)->willReturn([]);

		$this->assertSame(200, (new ExportController('tickbuddy', $this->request, $service, 'testuser'))->export($value)->getStatus());
	}

	/**
	 * @dataProvider unreadableProvider
	 */
	public function testExportRefusesIncludePrivate(string $value): void {
		$service = $this->createMock(ExportService::class);
		$service->expects($this->never())->method('export');

		$this->assertRefused((new ExportController('tickbuddy', $this->request, $service, 'testuser'))->export($value), 'includePrivate');
	}

	// --- writes that take a key ---

	private function ticks(TickService $service): TickController {
		return new TickController('tickbuddy', $this->request, $service, $this->idempotency(), 'testuser');
	}

	/**
	 * @dataProvider readableProvider
	 */
	public function testTickWritesReadAllowDuplicateNote(string|int|bool $value, bool $expected): void {
		$service = $this->createMock(TickService::class);
		$service->expects($this->once())->method('toggle')->with('testuser', 1, '2026-11-14', null, 'a', $expected)->willReturn(['ticked' => true, 'value' => 1]);
		$service->expects($this->once())->method('set')->with('testuser', 1, '2026-11-14', 1, null, 'a', $expected)->willReturn(['value' => 1]);
		$service->expects($this->once())->method('increment')->with('testuser', 1, '2026-11-14', 1, null, 'a', $expected)->willReturn(['value' => 1]);
		$controller = $this->ticks($service);

		$this->assertSame(200, $controller->toggle(1, '2026-11-14', appendNote: 'a', allowDuplicateNote: $value)->getStatus());
		$this->assertSame(200, $controller->set(1, '2026-11-14', 1, appendNote: 'a', allowDuplicateNote: $value)->getStatus());
		$this->assertSame(200, $controller->increment(1, '2026-11-14', 1, appendNote: 'a', allowDuplicateNote: $value)->getStatus());
	}

	/**
	 * @dataProvider unreadableProvider
	 */
	public function testTickWritesRefuseAllowDuplicateNoteBeforeAnything(string $value): void {
		$service = $this->createMock(TickService::class);
		$service->expects($this->never())->method('toggle');
		$service->expects($this->never())->method('set');
		$service->expects($this->never())->method('increment');
		$controller = $this->ticks($service);

		$this->assertRefused($controller->toggle(1, '2026-11-14', allowDuplicateNote: $value), 'allowDuplicateNote');
		$this->assertRefused($controller->set(1, '2026-11-14', 1, allowDuplicateNote: $value), 'allowDuplicateNote');
		$this->assertRefused($controller->increment(1, '2026-11-14', 1, allowDuplicateNote: $value), 'allowDuplicateNote');
	}

	public function testIncrementPassesTheDeltaRead(): void {
		$service = $this->createMock(TickService::class);
		$service->expects($this->exactly(3))->method('increment')
			->willReturnCallback(function (string $userId, int $trackId, string $date, ?int $delta): array {
				return ['value' => $delta ?? -1];
			});
		$controller = $this->ticks($service);

		$this->assertSame(['value' => 3], $controller->increment(1, '2026-11-14', ' 3 ')->getData());
		$this->assertSame(['value' => -2], $controller->increment(1, '2026-11-14', -2)->getData());
		// The service refuses a delta it couldn't read, after the track lookup
		$this->assertSame(['value' => -1], $controller->increment(1, '2026-11-14', '5abc')->getData());
	}

	/**
	 * @dataProvider readableProvider
	 */
	public function testNoteAppendReadsAllowDuplicate(string|int|bool $value, bool $expected): void {
		$service = $this->createMock(NoteService::class);
		$service->expects($this->once())->method('append')->with('testuser', 1, '2026-11-14', 'a', $expected)->willReturn('a');
		$controller = new NoteController('tickbuddy', $this->request, $service, $this->idempotency(), 'testuser');

		$this->assertSame(200, $controller->append(1, '2026-11-14', 'a', $value)->getStatus());
	}

	/**
	 * @dataProvider unreadableProvider
	 */
	public function testNoteAppendRefusesAllowDuplicate(string $value): void {
		$service = $this->createMock(NoteService::class);
		$service->expects($this->never())->method('append');
		$controller = new NoteController('tickbuddy', $this->request, $service, $this->idempotency(), 'testuser');

		$this->assertRefused($controller->append(1, '2026-11-14', 'a', $value), 'allowDuplicate');
	}

	// --- fingerprints ---

	/**
	 * The fingerprint each write claims its key with, with a key sent
	 *
	 * @param \Closure(): DataResponse $write
	 */
	private function fingerprintOf(\Closure $write): string {
		$this->request->method('getHeader')->willReturnCallback(fn (string $name) => $name === 'Idempotency-Key' ? '8e03978e-40d5-43e8-bc93-6894a57f9324' : '');
		$fingerprint = '';
		$this->idempotencyMapper->method('claim')->willReturnCallback(function (string $userId, string $key, string $claimed) use (&$fingerprint): IdempotencyKey {
			$fingerprint = $claimed;
			$row = new IdempotencyKey();
			$row->setId(1);
			return $row;
		});
		$write();
		return $fingerprint;
	}

	private function increments(): TickController {
		$service = $this->createMock(TickService::class);
		$service->method('increment')->willReturn(['value' => 1]);
		return $this->ticks($service);
	}

	public function testTickdroidsIncrementKeepsItsFingerprint(): void {
		// Tickdroid sends a form-encoded delta=1 with a key, which was cast to the integer 1
		$this->assertSame(
			'9dd1bee581f2c71a916c060bf86a8122deb1c7a54b271bb0ec08cb1554208044',
			$this->fingerprintOf(fn () => $this->increments()->increment(5, '2026-11-14', '1')),
		);
		$this->assertSame(
			'9dd1bee581f2c71a916c060bf86a8122deb1c7a54b271bb0ec08cb1554208044',
			$this->fingerprintOf(fn () => $this->increments()->increment(5, '2026-11-14', 1)),
		);
	}

	public function testAFlagSentAsTextKeepsTheFingerprintOfTheBoolean(): void {
		$text = $this->fingerprintOf(fn () => $this->increments()->increment(5, '2026-11-14', 1, allowDuplicateNote: 'false'));
		$this->assertSame(IdempotencyService::fingerprint('ticks.increment', [5, '2026-11-14', 1, null, null, false]), $text);
		$this->assertSame(
			IdempotencyService::fingerprint('notes.append', [1, '2026-11-14', 'a', true]),
			$this->fingerprintOf(function () {
				$service = $this->createMock(NoteService::class);
				$service->method('append')->willReturn('a');
				return (new NoteController('tickbuddy', $this->request, $service, $this->idempotency(), 'testuser'))->append(1, '2026-11-14', 'a', 'true');
			}),
		);
	}
}
