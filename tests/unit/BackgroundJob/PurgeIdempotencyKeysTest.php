<?php

declare(strict_types=1);

namespace BackgroundJob;

use OCA\Tickbuddy\BackgroundJob\PurgeIdempotencyKeys;
use OCA\Tickbuddy\Db\IdempotencyKeyMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class PurgeIdempotencyKeysTest extends TestCase {
	public function testDeletesKeysOlderThan30Days(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_792_000_000);
		$mapper = $this->createMock(IdempotencyKeyMapper::class);
		$mapper->expects($this->once())->method('deleteOlderThan')->with(1_792_000_000 - 30 * 24 * 3600);

		$job = new PurgeIdempotencyKeys($time, $mapper);
		// run() is protected; TimedJob::start() would also need a job list
		(fn () => $this->run(null))->call($job);

		$this->assertSame(24 * 3600, $job->getInterval());
		$this->assertFalse($job->isTimeSensitive());
	}
}
