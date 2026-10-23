<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\IdempotencyKey;
use OCA\Tickbuddy\Db\IdempotencyKeyMapper;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class IdempotencyServiceTest extends TestCase {
	private const KEY = '8e03978e-40d5-43e8-bc93-6894a57f9324';
	private const NOW = 1_792_000_000;

	private IRequest $request;
	private IdempotencyKeyMapper $mapper;
	private IdempotencyService $service;
	private int $handlerRuns = 0;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(IdempotencyKeyMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->service = new IdempotencyService($this->request, $this->mapper, $time, $this->createMock(LoggerInterface::class));
	}

	private function withHeader(string $value): void {
		$this->request->method('getHeader')->with('Idempotency-Key')->willReturn($value);
	}

	/** Increment +1 on track 2, answered with `$response` */
	private function increment(int $delta = 1, ?DataResponse $response = null): DataResponse {
		return $this->service->run('testuser', 'ticks.increment', [2, '2026-10-23', $delta, null, null, false], function () use ($response): DataResponse {
			$this->handlerRuns++;
			return $response ?? new DataResponse(['value' => 4]);
		});
	}

	private function fingerprint(int $delta = 1): string {
		return IdempotencyService::fingerprint('ticks.increment', [2, '2026-10-23', $delta, null, null, false]);
	}

	private function row(string $fingerprint, ?int $status = null, ?string $response = null, int $createdAt = self::NOW): IdempotencyKey {
		$row = new IdempotencyKey();
		$row->setId(7);
		$row->setUserId('testuser');
		$row->setIdemKey(self::KEY);
		$row->setFingerprint($fingerprint);
		$row->setStatus($status);
		$row->setResponse($response);
		$row->setCreatedAt($createdAt);
		return $row;
	}

	private function claimed(int $id = 7): IdempotencyKey {
		$row = $this->row($this->fingerprint());
		$row->setId($id);
		return $row;
	}

	private function uniqueViolation(): DbException {
		$e = $this->createMock(DbException::class);
		$e->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		return $e;
	}

	// --- parseKey ---

	public static function validKeys(): array {
		return [
			'quoted' => ['"8e03978e-40d5-43e8-bc93-6894a57f9324"'],
			'unquoted' => ['8e03978e-40d5-43e8-bc93-6894a57f9324'],
			'upper case' => ['"8E03978E-40D5-43E8-BC93-6894A57F9324"'],
			'spaces around' => [' "8e03978e-40d5-43e8-bc93-6894a57f9324" '],
		];
	}

	/**
	 * @dataProvider validKeys
	 */
	public function testParseKeyGivesTheLowerCaseUuid(string $header): void {
		$this->assertSame(self::KEY, IdempotencyService::parseKey($header));
	}

	public static function invalidKeys(): array {
		return [
			'empty string' => ['""'],
			'not a uuid' => ['"abc"'],
			'braces' => ['{8e03978e-40d5-43e8-bc93-6894a57f9324}'],
			'inner quote' => ['"8e03978e-40d5-43e8-bc93-6894a57f932"4"'],
			'37 characters' => ['8e03978e-40d5-43e8-bc93-6894a57f93245'],
			'no hyphens' => ['8e03978e40d543e8bc936894a57f9324'],
			'trailing newline inside quotes' => ["\"8e03978e-40d5-43e8-bc93-6894a57f9324\n\""],
			'two keys' => ['"8e03978e-40d5-43e8-bc93-6894a57f9324", "8e03978e-40d5-43e8-bc93-6894a57f9325"'],
		];
	}

	/**
	 * @dataProvider invalidKeys
	 */
	public function testParseKeyRefusesWhatIsNotAUuid(string $header): void {
		$this->assertNull(IdempotencyService::parseKey($header));
	}

	// --- fingerprint ---

	public function testFingerprintIsStable(): void {
		$this->assertSame($this->fingerprint(), $this->fingerprint());
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->fingerprint());
	}

	public function testFingerprintDiffersWhenAnyParameterDiffers(): void {
		$base = [2, '2026-10-23', 1, null, null, false];
		$variants = [
			[3, '2026-10-23', 1, null, null, false],
			[2, '2026-10-24', 1, null, null, false],
			[2, '2026-10-23', 2, null, null, false],
			[2, '2026-10-23', 1, '', null, false],
			[2, '2026-10-23', 1, null, '', false],
			[2, '2026-10-23', 1, null, null, true],
			[2, '2026-10-23', '1', null, null, false],
		];
		$fingerprint = IdempotencyService::fingerprint('ticks.increment', $base);
		foreach ($variants as $variant) {
			$this->assertNotSame($fingerprint, IdempotencyService::fingerprint('ticks.increment', $variant));
		}
		$this->assertNotSame($fingerprint, IdempotencyService::fingerprint('ticks.set', $base));
	}

	public function testFingerprintTakesTextThatIsNotUtf8(): void {
		$this->assertNotSame(
			IdempotencyService::fingerprint('notes.append', [1, '2026-10-23', "\xff", false]),
			IdempotencyService::fingerprint('notes.append', [1, '2026-10-23', "\xfe", false]),
		);
	}

	// --- run ---

	public function testWithoutAKeyTheHandlerRunsAndNothingIsClaimed(): void {
		$this->withHeader('');
		$this->mapper->expects($this->never())->method('claim');

		$response = $this->increment();

		$this->assertSame(1, $this->handlerRuns);
		$this->assertSame(['value' => 4], $response->getData());
	}

	public function testAnInvalidKeyIsRefusedAndNothingRuns(): void {
		$this->withHeader('abc');
		$this->mapper->expects($this->never())->method('claim');

		$response = $this->increment();

		$this->assertSame(0, $this->handlerRuns);
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'Idempotency-Key must be a UUID', 'reason' => 'invalidValue'], $response->getData());
	}

	public function testTheFirstRequestIsClaimedRunAndItsAnswerStored(): void {
		$this->withHeader('"8E03978E-40D5-43E8-BC93-6894A57F9324"');
		$this->mapper->expects($this->once())->method('claim')
			->with('testuser', self::KEY, $this->fingerprint(), self::NOW)
			->willReturn($this->claimed(7));
		$this->mapper->expects($this->once())->method('storeAnswer')->with(7, 200, '{"value":4}');

		$response = $this->increment();

		$this->assertSame(1, $this->handlerRuns);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['value' => 4], $response->getData());
	}

	public static function errorAnswers(): array {
		return [
			'archived' => [423, ['message' => 'Exercise is archived, so nothing was changed', 'reason' => 'archived']],
			'note conflict' => [409, ['message' => 'The note changed while appending; try again', 'reason' => 'noteConflict']],
		];
	}

	/**
	 * @dataProvider errorAnswers
	 */
	public function testAnErrorAnswerIsStoredToo(int $status, array $data): void {
		$this->withHeader(self::KEY);
		$this->mapper->method('claim')->willReturn($this->claimed(7));
		$this->mapper->expects($this->once())->method('storeAnswer')->with(7, $status, json_encode($data));

		$response = $this->increment(1, new DataResponse($data, $status));

		$this->assertSame($status, $response->getStatus());
	}

	public function testARepeatGetsTheStoredAnswerWithoutRunning(): void {
		$this->withHeader(self::KEY);
		$this->mapper->method('claim')->willThrowException($this->uniqueViolation());
		$this->mapper->method('find')->with('testuser', self::KEY)
			->willReturn($this->row($this->fingerprint(), 200, '{"value":4,"note":"Ran 5k"}', self::NOW - 3600));
		$this->mapper->expects($this->never())->method('storeAnswer');

		$response = $this->increment();

		$this->assertSame(0, $this->handlerRuns);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['value' => 4, 'note' => 'Ran 5k'], $response->getData());
	}

	public function testARepeatOfAnErrorGetsTheStoredError(): void {
		$this->withHeader(self::KEY);
		$this->mapper->method('claim')->willThrowException($this->uniqueViolation());
		$this->mapper->method('find')
			->willReturn($this->row($this->fingerprint(), 423, '{"message":"Exercise is archived, so nothing was changed","reason":"archived"}'));

		$response = $this->increment();

		$this->assertSame(0, $this->handlerRuns);
		$this->assertSame(423, $response->getStatus());
		$this->assertSame(['message' => 'Exercise is archived, so nothing was changed', 'reason' => 'archived'], $response->getData());
	}

	public function testTheSameKeyForAnotherRequestIsRefused(): void {
		$this->withHeader(self::KEY);
		$this->mapper->method('claim')->willThrowException($this->uniqueViolation());
		$this->mapper->method('find')->willReturn($this->row($this->fingerprint(1), 200, '{"value":4}'));

		$response = $this->increment(2);

		$this->assertSame(0, $this->handlerRuns);
		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['message' => 'This Idempotency-Key was already used for another request', 'reason' => 'idempotencyKeyReused'], $response->getData());
	}

	public function testARepeatWhileTheFirstRunsIsToldToTryAgain(): void {
		$this->withHeader(self::KEY);
		$this->mapper->method('claim')->willThrowException($this->uniqueViolation());
		$this->mapper->method('find')->willReturn($this->row($this->fingerprint(), createdAt: self::NOW - 60));
		$this->mapper->expects($this->never())->method('releaseStale');

		$response = $this->increment();

		$this->assertSame(0, $this->handlerRuns);
		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['message' => 'This request is still being handled; try again shortly', 'reason' => 'requestInProgress'], $response->getData());
	}

	public function testAStaleClaimIsReleasedAndTheRepeatHandledAsNew(): void {
		$this->withHeader(self::KEY);
		$this->mapper->expects($this->exactly(2))->method('claim')
			->willReturnOnConsecutiveCalls($this->throwException($this->uniqueViolation()), $this->claimed(8));
		$this->mapper->method('find')->willReturn($this->row($this->fingerprint(), createdAt: self::NOW - 360));
		$this->mapper->expects($this->once())->method('releaseStale')->with(7, self::NOW - 300);
		$this->mapper->expects($this->once())->method('storeAnswer')->with(8, 200, '{"value":4}');

		$response = $this->increment();

		$this->assertSame(1, $this->handlerRuns);
		$this->assertSame(['value' => 4], $response->getData());
	}

	public function testAStaleClaimAnotherRepeatReleasedFirstAnswers409(): void {
		$this->withHeader(self::KEY);
		// Our release deletes nothing: the other repeat released the claim and made its own
		$this->mapper->method('claim')->willThrowException($this->uniqueViolation());
		$this->mapper->method('find')->willReturnOnConsecutiveCalls(
			$this->row($this->fingerprint(), createdAt: self::NOW - 360),
			$this->row($this->fingerprint(), createdAt: self::NOW),
		);

		$response = $this->increment();

		$this->assertSame(0, $this->handlerRuns);
		$this->assertSame(409, $response->getStatus());
		$this->assertSame('requestInProgress', $response->getData()['reason']);
	}

	public function testAClaimGoneBeforeItIsReadIsClaimedAgain(): void {
		$this->withHeader(self::KEY);
		$this->mapper->method('claim')
			->willReturnOnConsecutiveCalls($this->throwException($this->uniqueViolation()), $this->claimed(8));
		$this->mapper->method('find')->willThrowException(new DoesNotExistException(''));

		$this->increment();

		$this->assertSame(1, $this->handlerRuns);
	}

	public function testOtherDatabaseErrorsWhileClaimingAreNotSwallowed(): void {
		$this->withHeader(self::KEY);
		$this->mapper->method('claim')->willThrowException(new DbException('Connection lost'));

		$this->expectException(DbException::class);
		try {
			$this->increment();
		} finally {
			$this->assertSame(0, $this->handlerRuns);
		}
	}

	public function testTheClientGetsItsAnswerWhenStoringItFails(): void {
		$this->withHeader(self::KEY);
		$this->mapper->method('claim')->willReturn($this->claimed(7));
		$this->mapper->method('storeAnswer')->willThrowException(new DbException('Connection lost'));

		$response = $this->increment();

		$this->assertSame(1, $this->handlerRuns);
		$this->assertSame(['value' => 4], $response->getData());
	}
}
