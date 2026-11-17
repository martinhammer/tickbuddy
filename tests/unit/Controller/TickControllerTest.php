<?php

declare(strict_types=1);

namespace Controller;

use OCA\Tickbuddy\Controller\TickController;
use OCA\Tickbuddy\Db\IdempotencyKeyMapper;
use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCA\Tickbuddy\Service\TickService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Number and Choice values on the tick endpoints.
 */
final class TickControllerTest extends TestCase {
	private TickService $ticks;

	protected function setUp(): void {
		$this->ticks = $this->createMock(TickService::class);
	}

	/**
	 * @param array<string, mixed> $params What the request held
	 */
	private function controller(array $params = []): TickController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$idempotency = new IdempotencyService($request, $this->createMock(IdempotencyKeyMapper::class), $this->createMock(ITimeFactory::class), $this->createMock(LoggerInterface::class));
		return new TickController('tickbuddy', $request, $this->ticks, $idempotency, 'testuser');
	}

	private static function makeTick(int $trackId, int $value): Tick {
		$tick = new Tick();
		$tick->setId($trackId * 10);
		$tick->setTrackId($trackId);
		$tick->setDate('2026-11-17');
		$tick->setValue($value);
		return $tick;
	}

	public function testSetTellsAMissingValueFromNull(): void {
		$sent = [];
		$this->ticks->method('set')->willReturnCallback(function (string $userId, int $trackId, string $date, mixed $value, mixed $note, mixed $appendNote, bool $allowDuplicate, bool $valueSent) use (&$sent): array {
			$sent[] = $valueSent;
			return ['value' => null];
		});

		$this->controller()->set(3, '2026-11-17');
		$this->controller(['trackId' => 3, 'date' => '2026-11-17', 'value' => null])->set(3, '2026-11-17', null);
		$this->controller(['value' => ''])->set(3, '2026-11-17', '');

		$this->assertSame([false, true, true], $sent);
	}

	public function testListWritesEachValueAsItsTrackDoes(): void {
		$this->ticks->method('findByDateRange')->willReturn([self::makeTick(1, 4), self::makeTick(2, 10450), self::makeTick(3, 2), self::makeTick(2, 72000)]);
		$this->ticks->method('trackTypes')->willReturn([1 => 'counter', 2 => 'number', 3 => 'choice']);

		$data = $this->controller()->index('2026-11-01', '2026-11-17', includeAllTypes: 'true')->getData();

		$this->assertSame([4, 10.45, 2, 72], array_column($data, 'value'));
		$this->assertSame('[4,10.45,2,72]', json_encode(array_column($data, 'value')));
	}

	public function testListPassesIncludeAllTypes(): void {
		$this->ticks->expects($this->exactly(2))->method('findByDateRange')
			->willReturnCallback(function (string $userId, string $from, string $to, ?int $trackId, bool $includeAllTypes): array {
				static $calls = 0;
				$this->assertSame(++$calls === 2, $includeAllTypes);
				return [];
			});
		$this->ticks->expects($this->never())->method('trackTypes');

		$this->controller()->index('2026-11-01', '2026-11-17');
		$this->controller()->index('2026-11-01', '2026-11-17', includeAllTypes: '1');
	}

	public function testListRefusesAnUnreadableIncludeAllTypes(): void {
		$response = $this->controller()->index('2026-11-01', '2026-11-17', includeAllTypes: 'maybe');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'includeAllTypes must be true or false', 'reason' => 'invalidValue'], $response->getData());
	}
}
