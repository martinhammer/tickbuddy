<?php

declare(strict_types=1);

namespace Controller;

use OCA\Tickbuddy\Controller\ImportController;
use OCA\Tickbuddy\Controller\NoteController;
use OCA\Tickbuddy\Controller\StatsController;
use OCA\Tickbuddy\Controller\TickController;
use OCA\Tickbuddy\Controller\TrackController;
use OCA\Tickbuddy\Service\ImportException;
use OCA\Tickbuddy\Service\ImportService;
use OCA\Tickbuddy\Service\InvalidArchiveDateException;
use OCA\Tickbuddy\Service\InvalidTrackNameException;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\NoteConflictException;
use OCA\Tickbuddy\Service\NoteService;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
use OCA\Tickbuddy\Service\StatsService;
use OCA\Tickbuddy\Service\TickService;
use OCA\Tickbuddy\Service\TrackLimitReachedException;
use OCA\Tickbuddy\Service\TrackService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\Exception as DbException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Every error answer carries its `reason` next to the unchanged message and status.
 */
final class ErrorReasonTest extends TestCase {
	private IRequest $request;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
	}

	private function assertError(DataResponse $response, int $status, string $message, string $reason): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['message' => $message, 'reason' => $reason], $response->getData());
	}

	private function readOnly(): ReadOnlyTrackException {
		return new ReadOnlyTrackException('Exercise is archived, so nothing was changed', 'archived');
	}

	// --- ticks ---

	private function ticks(\Throwable $e): TickController {
		$service = $this->createMock(TickService::class);
		$service->method('toggle')->willThrowException($e);
		$service->method('set')->willThrowException($e);
		$service->method('increment')->willThrowException($e);
		return new TickController('tickbuddy', $this->request, $service, 'testuser');
	}

	public static function tickErrors(): array {
		return [
			'track not found' => [new DoesNotExistException(''), 404, 'Track not found', 'trackNotFound'],
			'wrong track type' => [new InvalidTrackTypeException('Toggle is only available for boolean tracks'), 400, 'Toggle is only available for boolean tracks', 'wrongTrackType'],
			'invalid value' => [new InvalidValueException('Date must be a date as YYYY-MM-DD'), 400, 'Date must be a date as YYYY-MM-DD', 'invalidValue'],
			'note conflict' => [new NoteConflictException('The note changed while appending; try again'), 409, 'The note changed while appending; try again', 'noteConflict'],
			'archived' => [new ReadOnlyTrackException('Exercise is archived, so nothing was changed', 'archived'), 423, 'Exercise is archived, so nothing was changed', 'archived'],
		];
	}

	/**
	 * @dataProvider tickErrors
	 */
	public function testTickErrors(\Throwable $e, int $status, string $message, string $reason): void {
		$controller = $this->ticks($e);
		$this->assertError($controller->toggle(1, '2026-10-20'), $status, $message, $reason);
		$this->assertError($controller->set(1, '2026-10-20', 1), $status, $message, $reason);
		$this->assertError($controller->increment(1, '2026-10-20', 1), $status, $message, $reason);
	}

	// --- notes ---

	private function notes(\Throwable $e): NoteController {
		$service = $this->createMock(NoteService::class);
		$service->method('findByDateRange')->willThrowException($e);
		$service->method('set')->willThrowException($e);
		$service->method('append')->willThrowException($e);
		$service->method('delete')->willThrowException($e);
		return new NoteController('tickbuddy', $this->request, $service, 'testuser');
	}

	public function testNoteErrors(): void {
		$notFound = $this->notes(new DoesNotExistException(''));
		$this->assertError($notFound->set(1, '2026-10-20', 'a'), 404, 'Track not found', 'trackNotFound');
		$this->assertError($notFound->append(1, '2026-10-20', 'a'), 404, 'Track not found', 'trackNotFound');
		$this->assertError($notFound->destroy(1, '2026-10-20'), 404, 'Track not found', 'trackNotFound');

		$invalid = $this->notes(new InvalidValueException('Date must be a date as YYYY-MM-DD'));
		$this->assertError($invalid->index('x', 'y'), 400, 'Date must be a date as YYYY-MM-DD', 'invalidValue');
		$this->assertError($invalid->set(1, 'x', 'a'), 400, 'Date must be a date as YYYY-MM-DD', 'invalidValue');
		$this->assertError($invalid->append(1, 'x', 'a'), 400, 'Date must be a date as YYYY-MM-DD', 'invalidValue');
		$this->assertError($invalid->destroy(1, 'x'), 400, 'Date must be a date as YYYY-MM-DD', 'invalidValue');

		$conflict = $this->notes(new NoteConflictException('The note changed while appending; try again'));
		$this->assertError($conflict->append(1, '2026-10-20', 'a'), 409, 'The note changed while appending; try again', 'noteConflict');

		$archived = $this->notes($this->readOnly());
		$this->assertError($archived->set(1, '2026-10-20', 'a'), 423, 'Exercise is archived, so nothing was changed', 'archived');
		$this->assertError($archived->append(1, '2026-10-20', 'a'), 423, 'Exercise is archived, so nothing was changed', 'archived');
		$this->assertError($archived->destroy(1, '2026-10-20'), 423, 'Exercise is archived, so nothing was changed', 'archived');
	}

	// --- tracks ---

	private function tracks(\Throwable $e): TrackController {
		$service = $this->createMock(TrackService::class);
		$service->method('create')->willThrowException($e);
		$service->method('update')->willThrowException($e);
		$service->method('reorder')->willThrowException($e);
		$service->method('delete')->willThrowException($e);
		return new TrackController('tickbuddy', $this->request, $service, 'testuser');
	}

	public function testCreateErrors(): void {
		$this->assertError($this->tracks(new InvalidTrackNameException('Track name cannot be empty'))->create('', 'boolean'),
			400, 'Track name cannot be empty', 'invalidValue');
		// An unknown type is a bad value here, unlike the ticks' wrong endpoint
		$this->assertError($this->tracks(new InvalidTrackTypeException('Invalid track type: x'))->create('A', 'x'),
			400, 'Invalid track type: x', 'invalidValue');
		$this->assertError($this->tracks(new TrackLimitReachedException('Maximum of 99 tracks reached'))->create('A', 'boolean'),
			403, 'Maximum of 99 tracks reached', 'trackLimitReached');
	}

	public function testUpdateErrors(): void {
		$this->assertError($this->tracks(new DoesNotExistException(''))->update(1, 'A'), 404, 'Track not found', 'trackNotFound');
		$this->assertError($this->tracks(new InvalidTrackNameException('Track name cannot be empty'))->update(1, ' '),
			400, 'Track name cannot be empty', 'invalidValue');
		$this->assertError($this->tracks(new InvalidArchiveDateException('Archive date must be a date as YYYY-MM-DD'))->update(1, archived: true, archivedAt: 'x'),
			400, 'Archive date must be a date as YYYY-MM-DD', 'invalidValue');
		$this->assertError($this->tracks(new DbException('Unique constraint'))->update(1, sortOrder: 1),
			409, 'Unique constraint', 'databaseConflict');
		$this->assertError($this->tracks($this->readOnly())->update(1, 'A'), 423, 'Exercise is archived, so nothing was changed', 'archived');
	}

	public function testReorderErrors(): void {
		$this->assertError($this->tracks($this->readOnly())->reorder([]), 400, 'trackIds is required', 'invalidValue');
		$this->assertError($this->tracks($this->readOnly())->reorder([1, 2]), 423, 'Exercise is archived, so nothing was changed', 'archived');
	}

	public function testDestroyErrors(): void {
		$this->assertError($this->tracks(new DoesNotExistException(''))->destroy(1), 404, 'Track not found', 'trackNotFound');
	}

	// --- stats ---

	public function testStatsErrors(): void {
		$service = $this->createMock(StatsService::class);
		$service->method('forUser')->willReturnCallback(function (string $userId, string $today) {
			throw $today === 'x'
				? new InvalidValueException('Today must be a date as YYYY-MM-DD')
				: new DoesNotExistException('');
		});
		$controller = new StatsController('tickbuddy', $this->request, $service, 'testuser');

		$this->assertError($controller->index('x'), 400, 'Today must be a date as YYYY-MM-DD', 'invalidValue');
		$this->assertError($controller->index('2026-10-20', trackId: 99), 404, 'Track not found', 'trackNotFound');
	}

	// --- imports ---

	private function imports(?array $file, ?\Throwable $e = null): ImportController {
		$this->request->method('getUploadedFile')->willReturn($file);
		$service = $this->createMock(ImportService::class);
		if ($e !== null) {
			$service->method('importTickmate')->willThrowException($e);
			$service->method('importJson')->willThrowException($e);
		}
		return new ImportController('tickbuddy', $this->request, $service, 'testuser');
	}

	public function testImportWithoutAFile(): void {
		$controller = $this->imports(null);
		$this->assertError($controller->import('merge'), 400, 'No file uploaded or upload error', 'invalidUpload');
		$this->assertError($controller->importJson('merge'), 400, 'No file uploaded or upload error', 'invalidUpload');
	}

	public function testImportOfAFileTooLarge(): void {
		$controller = $this->imports(['tmp_name' => '/tmp/x', 'size' => 21 * 1024 * 1024, 'error' => UPLOAD_ERR_OK]);
		$this->assertError($controller->importJson('merge'), 400, 'File too large (max 20 MB).', 'invalidUpload');
	}

	public function testImportWithAnInvalidMode(): void {
		$controller = $this->imports(['tmp_name' => '/tmp/x', 'size' => 10, 'error' => UPLOAD_ERR_OK]);
		$this->assertError($controller->importJson('x'), 400, 'Invalid mode. Use "replace" or "merge".', 'invalidValue');
	}

	public function testImportRefused(): void {
		$controller = $this->imports(['tmp_name' => '/tmp/x', 'size' => 10, 'error' => UPLOAD_ERR_OK], new ImportException('Not a Tickbuddy export'));
		$this->assertError($controller->import('merge'), 400, 'Not a Tickbuddy export', 'importFailed');
		$this->assertError($controller->importJson('replace'), 400, 'Not a Tickbuddy export', 'importFailed');
	}
}
