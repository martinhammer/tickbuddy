<?php

declare(strict_types=1);

namespace Controller;

use OCA\Tickbuddy\Controller\TrackController;
use OCA\Tickbuddy\Db\IdempotencyKeyMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackOption;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\OptionNotFoundException;
use OCA\Tickbuddy\Service\PublicLinkService;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
use OCA\Tickbuddy\Service\TrackService;
use OCA\Tickbuddy\Service\TrackSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `publicLinkCount` and the type settings on every answer that holds tracks.
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

	private function makeTypedTrack(int $id, string $type): Track {
		$track = $this->makeTrack($id);
		$track->setType($type);
		if ($type === 'number') {
			$track->setUnit('km');
			$track->setDecimalPlaces(1);
			$track->setAllowNegative(0);
		} elseif ($type === 'choice') {
			$track->setOrderedScale(1);
		}
		return $track;
	}

	/** @return list<TrackOption> */
	private static function options(int $trackId, string ...$labels): array {
		$options = [];
		foreach ($labels as $index => $label) {
			$option = new TrackOption();
			$option->setTrackId($trackId);
			$option->setPlace($index + 1);
			$option->setLabel($label);
			$options[] = $option;
		}
		return $options;
	}

	public function testEveryTrackCarriesEveryTypesSettings(): void {
		$counter = $this->makeTypedTrack(1, 'counter');
		$number = $this->makeTypedTrack(2, 'number');
		$noUnit = $this->makeTypedTrack(3, 'number');
		$noUnit->setUnit(null);
		$choice = $this->makeTypedTrack(4, 'choice');
		$this->tracks->method('findAll')->willReturn([$counter, $number, $noUnit, $choice]);
		$this->tracks->expects($this->once())->method('optionsFor')->willReturn([4 => self::options(4, 'Excited', 'Happy')]);

		$data = $this->controller()->index(includeAllTypes: 'true')->getData();

		$fields = ['unit', 'decimalPlaces', 'allowNegative', 'options', 'orderedScale'];
		$settings = array_map(fn (array $t) => array_intersect_key($t, array_flip($fields)), $data);
		$this->assertSame([
			['unit' => null, 'decimalPlaces' => null, 'allowNegative' => null, 'options' => null, 'orderedScale' => null],
			['unit' => 'km', 'decimalPlaces' => 1, 'allowNegative' => false, 'options' => null, 'orderedScale' => null],
			['unit' => '', 'decimalPlaces' => 1, 'allowNegative' => false, 'options' => null, 'orderedScale' => null],
			['unit' => null, 'decimalPlaces' => null, 'allowNegative' => null, 'options' => [['value' => 1, 'label' => 'Excited'], ['value' => 2, 'label' => 'Happy']], 'orderedScale' => true],
		], $settings);
	}

	public function testListLeavesNewTypesOutUnlessAsked(): void {
		$this->tracks->expects($this->exactly(2))->method('findAll')
			->willReturnCallback(function (string $userId, bool $includeArchived, bool $includeAllTypes): array {
				static $calls = 0;
				$this->assertSame(++$calls === 2, $includeAllTypes);
				return [];
			});

		$this->controller()->index();
		$this->controller()->index(includeAllTypes: 'yes');
	}

	public function testListRefusesAnUnreadableIncludeAllTypes(): void {
		$this->tracks->expects($this->never())->method('findAll');

		$response = $this->controller()->index(includeAllTypes: 'maybe');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'includeAllTypes must be true or false', 'reason' => 'invalidValue'], $response->getData());
	}

	public function testReorderPassesIncludeAllTypes(): void {
		$this->tracks->expects($this->once())->method('reorder')->with([2, 1], 'testuser', true)->willReturn([]);

		$this->controller()->reorder([2, 1], 'true');
	}

	public function testCreatePassesTheSettings(): void {
		$this->tracks->expects($this->once())->method('create')
			->with('Mood', 'choice', 'testuser', $this->callback(fn (TrackSettings $s) => $s->options === ['A', 'B'] && $s->orderedScale === 'true'))
			->willReturn($this->makeTypedTrack(4, 'choice'));
		$this->tracks->method('options')->willReturn(self::options(4, 'A', 'B'));

		$response = $this->controller()->create('Mood', 'choice', options: ['A', 'B'], orderedScale: 'true');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame([['value' => 1, 'label' => 'A'], ['value' => 2, 'label' => 'B']], $response->getData()['options']);
	}

	public function testCreateAnswersABadSettingWith400(): void {
		$this->tracks->method('create')->willThrowException(new InvalidValueException('Choice tracks need 2 to 99 options'));

		$response = $this->controller()->create('Mood', 'choice', options: ['A']);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'Choice tracks need 2 to 99 options', 'reason' => 'invalidValue'], $response->getData());
	}

	public function testRenameOptionAnswersTheWholeTrack(): void {
		$this->tracks->expects($this->once())->method('renameOption')->with(4, 'testuser', 2, 'Glad')->willReturn($this->makeTypedTrack(4, 'choice'));
		$this->tracks->method('options')->willReturn(self::options(4, 'Excited', 'Glad'));

		$data = $this->controller()->renameOption(4, 2, 'Glad')->getData();

		$this->assertSame('Glad', $data['options'][1]['label']);
	}

	public static function renameErrorProvider(): array {
		return [
			'no track' => [new DoesNotExistException(''), 404, 'Track not found', 'trackNotFound'],
			'no option' => [new OptionNotFoundException('Option not found'), 404, 'Option not found', 'optionNotFound'],
			'not a choice' => [new InvalidTrackTypeException('Only choice tracks have options'), 400, 'Only choice tracks have options', 'wrongTrackType'],
			'bad label' => [new InvalidValueException('Option labels cannot be empty'), 400, 'Option labels cannot be empty', 'invalidValue'],
			'archived' => [new ReadOnlyTrackException('Mood is archived, so nothing was changed', 'archived'), 423, 'Mood is archived, so nothing was changed', 'archived'],
		];
	}

	/**
	 * @dataProvider renameErrorProvider
	 */
	public function testRenameOptionErrors(\Throwable $e, int $status, string $message, string $reason): void {
		$this->tracks->method('renameOption')->willThrowException($e);

		$response = $this->controller()->renameOption(4, 2, 'x');

		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['message' => $message, 'reason' => $reason], $response->getData());
	}

	public function testRenameOptionRouteTakesDigitsOnly(): void {
		$attributes = (new \ReflectionMethod(TrackController::class, 'renameOption'))->getAttributes(ApiRoute::class);
		$route = $attributes[0]->newInstance();

		$this->assertSame('/api/tracks/{id}/options/{value}', $route->getUrl());
		$this->assertSame(['id' => '\d+', 'value' => '\d+'], $route->getRequirements());
		$this->assertSame(1, preg_match('/^' . $route->getRequirements()['value'] . '$/', '2'));
		$this->assertSame(0, preg_match('/^' . $route->getRequirements()['value'] . '$/', 'x'));
	}
}
