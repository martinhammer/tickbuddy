<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\BackgroundJob;

use OCA\Tickbuddy\Db\IdempotencyKeyMapper;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily: forget `Idempotency-Key`s older than {@see IdempotencyService::KEEP_DAYS},
 * answered or not.
 *
 * @psalm-suppress UnusedClass Registered in appinfo/info.xml
 */
class PurgeIdempotencyKeys extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private IdempotencyKeyMapper $mapper,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run(mixed $argument): void {
		$this->mapper->deleteOlderThan($this->time->getTime() - IdempotencyService::KEEP_DAYS * 24 * 3600);
	}
}
