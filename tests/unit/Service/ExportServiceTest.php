<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\Note;
use OCA\Tickbuddy\Db\NoteMapper;
use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCA\Tickbuddy\Db\TrackOption;
use OCA\Tickbuddy\Db\TrackOptionMapper;
use OCA\Tickbuddy\Service\ExportService;
use PHPUnit\Framework\TestCase;

final class ExportServiceTest extends TestCase {
	private TickMapper $tickMapper;
	private NoteMapper $noteMapper;
	private TrackMapper $trackMapper;
	private TrackOptionMapper $optionMapper;
	private ExportService $service;
	private string $userId = 'testuser';

	protected function setUp(): void {
		$this->tickMapper = $this->createMock(TickMapper::class);
		$this->noteMapper = $this->createMock(NoteMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->optionMapper = $this->createMock(TrackOptionMapper::class);
		$this->service = new ExportService($this->trackMapper, $this->tickMapper, $this->noteMapper, $this->optionMapper);
	}

	private function makeTrack(int $id, string $name, string $type, int $sortOrder, bool $private = false, ?string $archivedAt = null): Track {
		$track = new Track();
		$track->setId($id);
		$track->setUserId($this->userId);
		$track->setName($name);
		$track->setType($type);
		$track->setSortOrder($sortOrder);
		$track->setPrivate($private ? 1 : 0);
		$track->setArchivedAt($archivedAt);
		return $track;
	}

	private function makeTick(int $trackId, string $date, int $value = 1): Tick {
		$tick = new Tick();
		$tick->setUserId($this->userId);
		$tick->setTrackId($trackId);
		$tick->setDate($date);
		$tick->setValue($value);
		return $tick;
	}

	public function testTicksOfDuplicateNamesCarryTrackIndex(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Cups of coffee', 'counter', 1),
			$this->makeTrack(20, 'Exercise', 'boolean', 2),
			$this->makeTrack(30, 'Exercise', 'boolean', 3),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([
			$this->makeTick(10, '2026-10-13', 2),
			$this->makeTick(20, '2026-10-13'),
			$this->makeTick(30, '2026-10-13'),
			$this->makeTick(30, '2026-03-01'),
		]);

		$result = $this->service->export($this->userId, true);

		$this->assertSame([
			['track' => 'Cups of coffee', 'date' => '2026-10-13', 'value' => 2],
			['track' => 'Exercise', 'trackIndex' => 1, 'date' => '2026-10-13', 'value' => 1],
			['track' => 'Exercise', 'trackIndex' => 2, 'date' => '2026-10-13', 'value' => 1],
			['track' => 'Exercise', 'trackIndex' => 2, 'date' => '2026-03-01', 'value' => 1],
		], $result['ticks']);
	}

	public function testUniqueNamesKeepOriginalTickShape(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Cups of coffee', 'counter', 1),
			$this->makeTrack(20, 'Exercise', 'boolean', 2),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([
			$this->makeTick(10, '2026-10-13', 2),
			$this->makeTick(20, '2026-10-13'),
		]);

		$result = $this->service->export($this->userId, true);

		$this->assertSame([
			['track' => 'Cups of coffee', 'date' => '2026-10-13', 'value' => 2],
			['track' => 'Exercise', 'date' => '2026-10-13', 'value' => 1],
		], $result['ticks']);
	}

	public function testPrivateTwinLeftOutMakesNameUnique(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Exercise', 'boolean', 1, true),
			$this->makeTrack(20, 'Cups of coffee', 'counter', 2),
			$this->makeTrack(30, 'Exercise', 'boolean', 3),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([
			$this->makeTick(10, '2026-10-12'),
			$this->makeTick(30, '2026-10-13'),
		]);

		$result = $this->service->export($this->userId, false);

		$this->assertSame(['Cups of coffee', 'Exercise'], array_column($result['tracks'], 'name'));
		$this->assertSame([
			['track' => 'Exercise', 'date' => '2026-10-13', 'value' => 1],
		], $result['ticks']);
	}

	public function testTrackIndexCountsOnlyExportedTracks(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Diary', 'boolean', 1, true),
			$this->makeTrack(20, 'Exercise', 'boolean', 2),
			$this->makeTrack(30, 'Exercise', 'boolean', 3),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([
			$this->makeTick(10, '2026-10-13'),
			$this->makeTick(20, '2026-10-13'),
			$this->makeTick(30, '2026-10-13'),
		]);

		$result = $this->service->export($this->userId, false);

		$this->assertSame([0, 1], array_column($result['ticks'], 'trackIndex'));
	}

	public function testArchivedTrackCarriesArchivedAt(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Exercise', 'boolean', 1),
			$this->makeTrack(20, 'Smoking', 'boolean', 2, false, '2026-08-14'),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([]);

		$result = $this->service->export($this->userId, true);

		$this->assertSame([
			['name' => 'Exercise', 'type' => 'boolean', 'sortOrder' => 1, 'private' => false],
			['name' => 'Smoking', 'type' => 'boolean', 'sortOrder' => 2, 'private' => false, 'archivedAt' => '2026-08-14'],
		], $result['tracks']);
	}

	public function testArchivedPrivateTrackFollowsIncludePrivate(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Exercise', 'boolean', 1),
			$this->makeTrack(20, 'Diary', 'boolean', 2, true, '2026-08-14'),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([]);

		$this->assertSame(['Exercise'], array_column($this->service->export($this->userId, false)['tracks'], 'name'));
		$this->assertSame(['Exercise', 'Diary'], array_column($this->service->export($this->userId, true)['tracks'], 'name'));
	}

	// --- notes ---

	private function makeNote(int $trackId, string $date, string $text): Note {
		$note = new Note();
		$note->setUserId($this->userId);
		$note->setTrackId($trackId);
		$note->setDate($date);
		$note->setText($text);
		return $note;
	}

	public function testNoNotesMeansNoNotesKey(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Exercise', 'boolean', 1),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([$this->makeTick(10, '2026-10-13')]);
		$this->noteMapper->method('findAllByUser')->willReturn([]);

		$result = $this->service->export($this->userId, true);

		$this->assertSame(['version', 'exportedAt', 'tracks', 'ticks'], array_keys($result));
	}

	public function testNotesCarryTrackIndexOnlyForRepeatedNames(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Backup', 'boolean', 1),
			$this->makeTrack(20, 'Exercise', 'boolean', 2),
			$this->makeTrack(30, 'Exercise', 'boolean', 3),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([]);
		$this->noteMapper->method('findAllByUser')->willReturn([
			$this->makeNote(10, '2026-10-13', 'done 03:14'),
			$this->makeNote(30, '2026-10-14', "ran\n5 km"),
		]);

		$result = $this->service->export($this->userId, true);

		$this->assertSame([
			['track' => 'Backup', 'date' => '2026-10-13', 'text' => 'done 03:14'],
			['track' => 'Exercise', 'trackIndex' => 2, 'date' => '2026-10-14', 'text' => "ran\n5 km"],
		], $result['notes']);
	}

	public function testPrivateTracksNotesLeftOut(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Backup', 'boolean', 1),
			$this->makeTrack(20, 'Diary', 'boolean', 2, true),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([]);
		$this->noteMapper->method('findAllByUser')->willReturn([
			$this->makeNote(20, '2026-10-13', 'secret'),
		]);

		$this->assertArrayNotHasKey('notes', $this->service->export($this->userId, false));
		$this->assertSame(
			[['track' => 'Diary', 'date' => '2026-10-13', 'text' => 'secret']],
			$this->service->export($this->userId, true)['notes'],
		);
	}

	public function testNumberAndChoiceCarryTheirSettingsAndValues(): void {
		$run = $this->makeTrack(10, 'Run', 'number', 1);
		$run->setUnit('km');
		$run->setDecimalPlaces(1);
		$run->setAllowNegative(0);
		$plain = $this->makeTrack(15, 'Weight', 'number', 2);
		$plain->setDecimalPlaces(2);
		$plain->setAllowNegative(1);
		$mood = $this->makeTrack(20, "Mum's mood", 'choice', 3, true);
		$mood->setOrderedScale(1);
		$this->trackMapper->method('findAllByUser')->willReturn([$this->makeTrack(5, 'Coffee', 'counter', 0), $run, $plain, $mood]);
		$options = [];
		foreach (['Excited', 'Happy'] as $index => $label) {
			$option = new TrackOption();
			$option->setTrackId(20);
			$option->setPlace($index + 1);
			$option->setLabel($label);
			$options[] = $option;
		}
		$this->optionMapper->expects($this->once())->method('findByTracks')->with([3 => 20], $this->userId)->willReturn([20 => $options]);
		$this->tickMapper->method('findAllByUser')->willReturn([
			$this->makeTick(10, '2026-11-16', 10450),
			$this->makeTick(10, '2026-11-17', 0),
			$this->makeTick(15, '2026-11-17', -72000),
			$this->makeTick(20, '2026-11-17', 2),
		]);

		$result = $this->service->export($this->userId, true);

		$this->assertSame([
			['name' => 'Coffee', 'type' => 'counter', 'sortOrder' => 0, 'private' => false],
			['name' => 'Run', 'type' => 'number', 'sortOrder' => 1, 'private' => false, 'unit' => 'km', 'decimalPlaces' => 1, 'allowNegative' => false],
			['name' => 'Weight', 'type' => 'number', 'sortOrder' => 2, 'private' => false, 'unit' => '', 'decimalPlaces' => 2, 'allowNegative' => true],
			['name' => "Mum's mood", 'type' => 'choice', 'sortOrder' => 3, 'private' => true, 'options' => ['Excited', 'Happy'], 'orderedScale' => true],
		], $result['tracks']);
		$this->assertSame('[10.45,0,-72,2]', json_encode(array_column($result['ticks'], 'value')));
	}
}
