<?php

declare(strict_types=1);

namespace Controller;

use OCA\Tickbuddy\Controller\PingController;
use OCA\Tickbuddy\Db\IdempotencyKey;
use OCA\Tickbuddy\Db\IdempotencyKeyMapper;
use OCA\Tickbuddy\Db\Note;
use OCA\Tickbuddy\Db\PublicLink;
use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\NoteConflictException;
use OCA\Tickbuddy\Service\NoteService;
use OCA\Tickbuddy\Service\PublicLinkService;
use OCA\Tickbuddy\Service\RequestBody;
use OCA\Tickbuddy\Service\TickService;
use OCA\Tickbuddy\Service\TrackService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The call a public link's address answers. The services are mocks: what is
 * checked here is what the call asks of them, and what it refuses first.
 */
final class PingControllerTest extends TestCase {
	private const TOKEN = 'Hx4abcdefgijkmnopqrstwxyzABCDEFG';
	private const TODAY = '2026-11-16';
	private const KEY = '2b0c8d4e-3f7a-4b1c-9d2e-5f6a7b8c9d0e';

	private IRequest $request;
	/** @var array<string, string> */
	private array $headers = [];
	private string $method = 'GET';
	private string $rawBody = '';
	private PublicLinkService $links;
	private TrackService $trackService;
	private TickService $tickService;
	private NoteService $noteService;
	private IdempotencyKeyMapper $idempotencyMapper;
	private Track $track;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(fn (string $name) => $this->headers[$name] ?? '');
		$this->request->method('getMethod')->willReturnCallback(fn () => $this->method);
		$this->links = $this->createMock(PublicLinkService::class);
		$this->links->method('todayFor')->willReturn(self::TODAY);
		$this->trackService = $this->createMock(TrackService::class);
		$this->tickService = $this->createMock(TickService::class);
		$this->noteService = $this->createMock(NoteService::class);
		$this->idempotencyMapper = $this->createMock(IdempotencyKeyMapper::class);
		$this->track = new Track();
		$this->track->setId(3);
		$this->track->setType('boolean');
		$this->trackService->method('find')->with(3, 'owner')->willReturnCallback(fn () => $this->track);
	}

	private function controller(): PingController {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_794_000_000);
		$idempotency = new IdempotencyService($this->request, $this->idempotencyMapper, $time, $this->createMock(LoggerInterface::class));
		$body = new class($this->request, fn () => $this->rawBody) extends RequestBody {
			/** @param \Closure(): string $raw */
			public function __construct(
				IRequest $request,
				private \Closure $raw,
			) {
				parent::__construct($request);
			}

			protected function read(): string {
				return ($this->raw)();
			}
		};
		return new PingController('tickbuddy', $this->request, $this->links, $this->trackService, $this->tickService, $this->noteService, $idempotency, $body);
	}

	private function givenLink(string $permission = 'tick', string $allowedDays = 'activeAndPrevious', bool $live = true): PublicLink {
		$link = new PublicLink();
		$link->setId(7);
		$link->setUserId('owner');
		$link->setTrackId(3);
		$link->setToken(self::TOKEN);
		$link->setPermission($permission);
		$link->setAllowedDays($allowedDays);
		$this->links->method('findByToken')->with(self::TOKEN)->willReturn($link);
		$this->links->method('isLive')->with($link)->willReturn($live);
		return $link;
	}

	private function counter(): void {
		$this->track->setType('counter');
	}

	private function assertError(DataResponse $response, int $status, string $message, string $reason): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['message' => $message, 'reason' => $reason], $response->getData());
	}

	private function assertNothingWritten(): void {
		$this->tickService->expects($this->never())->method('set');
		$this->tickService->expects($this->never())->method('increment');
	}

	private function postText(string $body, string $contentType = 'text/plain; charset=utf-8'): void {
		$this->method = 'POST';
		$this->headers['Content-Type'] = $contentType;
		$this->rawBody = $body;
	}

	// --- the link and its owner ---

	public function testAnUnknownTokenIsNotFoundAndCountsAsAGuess(): void {
		$this->links->method('findByToken')->willReturn(null);
		$this->links->expects($this->never())->method('touch');

		$response = $this->controller()->call('guess');

		$this->assertError($response, 404, 'Public link not found', 'publicLinkNotFound');
		$this->assertTrue($response->isThrottled());
	}

	/** A revoked link, or one whose owner can't sign in, doesn't slow the other links called from that server */
	public function testALinkNotLiveIsNotFoundWithoutCountingAsAGuess(): void {
		$this->givenLink(live: false);
		$this->links->expects($this->never())->method('touch');
		$this->assertNothingWritten();

		$response = $this->controller()->call(self::TOKEN);

		$this->assertError($response, 404, 'Public link not found', 'publicLinkNotFound');
		$this->assertFalse($response->isThrottled());
	}

	public function testAnswersJsonByDefault(): void {
		$format = (new \ReflectionMethod(PingController::class, 'buildResponse'))->getParameters()[1];

		$this->assertSame('json', $format->getDefaultValue());
	}

	// --- Tick only ---

	public function testAPlainCallTicksAYesNoDay(): void {
		$this->givenLink();
		$this->tickService->expects($this->once())->method('set')
			->with('owner', 3, self::TODAY, true, null, null, false)
			->willReturn(['value' => 1]);

		$response = $this->controller()->call(self::TOKEN);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['value' => 1], $response->getData());
	}

	public function testAPlainCallAddsOneToACounter(): void {
		$this->givenLink();
		$this->counter();
		$this->tickService->expects($this->never())->method('set');
		$this->tickService->expects($this->once())->method('increment')
			->with('owner', 3, self::TODAY, 1, null, null, false)
			->willReturn(['value' => 4]);

		$this->assertSame(['value' => 4], $this->controller()->call(self::TOKEN)->getData());
	}

	public static function valueProvider(): array {
		return [
			'yes/no untick' => ['boolean', '0'],
			'yes/no word' => ['boolean', 'yes'],
			'counter exact' => ['counter', '3'],
			'counter clear' => ['counter', '0'],
			// Sent empty is sent: the service refuses it, as on the API
			'empty' => ['counter', ''],
		];
	}

	/**
	 * @dataProvider valueProvider
	 */
	public function testAValueIsSetAsSent(string $type, string $value): void {
		$this->givenLink();
		$this->track->setType($type);
		$this->tickService->expects($this->never())->method('increment');
		$this->tickService->expects($this->once())->method('set')
			->with('owner', 3, self::TODAY, $value, null, null, false)
			->willReturn(['value' => 0]);

		$this->assertSame(200, $this->controller()->call(self::TOKEN, value: $value)->getStatus());
	}

	public function testABadValueIsTheServicesMessage(): void {
		$this->givenLink();
		$this->tickService->method('set')->willThrowException(new InvalidValueException('Yes/no tracks take a value of true or false'));

		$this->assertError($this->controller()->call(self::TOKEN, value: '2'), 400, 'Yes/no tracks take a value of true or false', 'invalidValue');
	}

	public function testAppendNoteAddsALineAndAnswersTheNote(): void {
		$this->givenLink();
		$this->tickService->expects($this->once())->method('set')
			->with('owner', 3, self::TODAY, true, null, '2.3 GB in 4 min', false)
			->willReturn(['value' => 1, 'note' => "Backup OK\n2.3 GB in 4 min"]);

		$this->assertSame(['value' => 1, 'note' => "Backup OK\n2.3 GB in 4 min"], $this->controller()->call(self::TOKEN, appendNote: '2.3 GB in 4 min')->getData());
	}

	public function testAPlainTextBodyIsTheTextToAppend(): void {
		$this->givenLink();
		$this->counter();
		$this->postText("Backup OK\n");
		$this->tickService->expects($this->once())->method('increment')
			->with('owner', 3, self::TODAY, 1, null, "Backup OK\n", false)
			->willReturn(['value' => 1, 'note' => 'Backup OK']);

		$this->assertSame(200, $this->controller()->call(self::TOKEN)->getStatus());
	}

	public static function notTextProvider(): array {
		return [
			'form' => ['POST', 'application/x-www-form-urlencoded'],
			'JSON' => ['POST', 'application/json'],
			'text/plain on GET' => ['GET', 'text/plain'],
			'text/plainer' => ['POST', 'text/plainer'],
		];
	}

	/**
	 * @dataProvider notTextProvider
	 */
	public function testOtherBodiesAreNotNotes(string $method, string $contentType): void {
		$this->givenLink();
		$this->method = $method;
		$this->headers['Content-Type'] = $contentType;
		$this->rawBody = 'Backup OK';
		$this->tickService->expects($this->once())->method('set')
			->with('owner', 3, self::TODAY, true, null, null, false)
			->willReturn(['value' => 1]);

		$this->controller()->call(self::TOKEN);
	}

	public function testAnEmptyTextBodyIsNoBody(): void {
		$this->givenLink();
		$this->postText('');
		$this->tickService->expects($this->once())->method('set')
			->with('owner', 3, self::TODAY, true, null, 'line', false)
			->willReturn(['value' => 1, 'note' => 'line']);

		$this->assertSame(200, $this->controller()->call(self::TOKEN, appendNote: 'line')->getStatus());
	}

	public function testATextBodyAndAppendNoteAreRefused(): void {
		$this->givenLink();
		$this->postText('Backup OK');
		$this->assertNothingWritten();

		$this->assertError($this->controller()->call(self::TOKEN, appendNote: 'Backup OK'), 400, 'Send appendNote or a text body, not both', 'invalidValue');
	}

	public function testABodyOver64KiBIsRefused(): void {
		$this->givenLink();
		$this->postText(str_repeat('a', 65537));
		$this->assertNothingWritten();

		$this->assertError($this->controller()->call(self::TOKEN), 400, 'Notes can be at most 500 characters', 'invalidValue');
	}

	public function testANoteTooLongRefusesTheCall(): void {
		$this->givenLink();
		// The service checks the note before it writes the tick
		$this->tickService->method('set')->willThrowException(new InvalidValueException('The note would be longer than 500 characters'));

		$this->assertError($this->controller()->call(self::TOKEN, appendNote: str_repeat('a', 400)), 400, 'The note would be longer than 500 characters', 'invalidValue');
	}

	public function testANoteConflictAfterTheTickIs409(): void {
		$this->givenLink();
		$this->tickService->method('set')->willThrowException(new NoteConflictException('The note changed while appending; try again'));

		$this->assertError($this->controller()->call(self::TOKEN, appendNote: 'x'), 409, 'The note changed while appending; try again', 'noteConflict');
	}

	public function testAnotherTrackTypeCantBeTickedYet(): void {
		$this->givenLink();
		$this->track->setType('number');
		$this->assertNothingWritten();

		$this->assertError($this->controller()->call(self::TOKEN), 400, "Public links can't tick tracks of this type yet", 'wrongTrackType');
	}

	public static function viewParameterProvider(): array {
		return [
			'from' => [['from' => '2026-11-10']],
			'to' => [['to' => '2026-11-16']],
			'includeNotes' => [['includeNotes' => 'true']],
		];
	}

	/**
	 * @dataProvider viewParameterProvider
	 */
	public function testATickLinkRefusesViewParameters(array $parameters): void {
		$this->givenLink();
		$this->assertNothingWritten();

		$this->assertError($this->controller()->call(self::TOKEN, ...$parameters), 400, "Tick only links don't read: leave out from, to and includeNotes", 'invalidValue');
	}

	public function testAnotherDayIsTicked(): void {
		$this->givenLink();
		$this->tickService->expects($this->once())->method('set')
			->with('owner', 3, '2026-11-15', true, null, null, false)
			->willReturn(['value' => 1]);

		$this->assertSame(200, $this->controller()->call(self::TOKEN, date: '2026-11-15')->getStatus());
	}

	public static function badDateProvider(): array {
		return [
			'not a real day' => ['2026-02-30'],
			'not a date' => ['yesterday'],
			'empty' => [''],
		];
	}

	/**
	 * @dataProvider badDateProvider
	 */
	public function testABadDateIsRefused(string $date): void {
		$this->givenLink();
		$this->assertNothingWritten();

		$this->assertError($this->controller()->call(self::TOKEN, date: $date), 400, 'Date must be a date as YYYY-MM-DD', 'invalidValue');
	}

	public static function allowedDaysProvider(): array {
		// The setting, its label, and a day it refuses: the day before its oldest
		// allowed day, or tomorrow, which no setting allows
		return [
			'Today, yesterday' => ['activeDay', 'Today', '2026-11-15'],
			'Today, tomorrow' => ['activeDay', 'Today', '2026-11-17'],
			'+1 day, two days ago' => ['activeAndPrevious', '+1 day', '2026-11-14'],
			'+1 day, tomorrow' => ['activeAndPrevious', '+1 day', '2026-11-17'],
			'1 week, seven days ago' => ['oneWeek', '1 week', '2026-11-09'],
			'1 week, tomorrow' => ['oneWeek', '1 week', '2026-11-17'],
			'All, tomorrow' => ['allDays', 'All', '2026-11-17'],
		];
	}

	/**
	 * @dataProvider allowedDaysProvider
	 */
	public function testATickCallOutsideTheAllowedDaysIsRefused(string $setting, string $label, string $day): void {
		$this->givenLink(allowedDays: $setting);
		$this->assertNothingWritten();

		$this->assertError($this->controller()->call(self::TOKEN, date: $day), 403, "This link's allowed days ($label) don't include $day", 'dayNotAllowed');
	}

	/** The day is the owner's, worked out by the service; a signed-in caller's session plays no part */
	public function testTheDayIsTodayForTheOwner(): void {
		$this->givenLink();
		$this->links->expects($this->atLeastOnce())->method('todayFor')->with('owner');
		$this->tickService->expects($this->once())->method('set')->with('owner')->willReturn(['value' => 1]);

		$this->controller()->call(self::TOKEN);
	}

	// --- last used ---

	public static function answerProvider(): array {
		return [
			'200' => [[], 200],
			'400' => [['date' => 'never'], 400],
			'403' => [['date' => '2026-11-01'], 403],
		];
	}

	/**
	 * @dataProvider answerProvider
	 */
	public function testEveryCallToALiveLinkCountsAsUsed(array $parameters, int $status): void {
		$link = $this->givenLink();
		$this->tickService->method('set')->willReturn(['value' => 1]);
		$this->links->expects($this->once())->method('touch')->with($link);

		$this->assertSame($status, $this->controller()->call(self::TOKEN, ...$parameters)->getStatus());
	}

	// --- Idempotency-Key ---

	private function storedKey(string $fingerprint, int $status, array $data): void {
		$this->headers['Idempotency-Key'] = self::KEY;
		$this->idempotencyMapper->method('claim')->willThrowException(new class('duplicate') extends DbException {
			public function getReason(): ?int {
				return DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		});
		$row = new IdempotencyKey();
		$row->setFingerprint($fingerprint);
		$row->setStatus($status);
		$row->setResponse(json_encode($data));
		$this->idempotencyMapper->method('find')->with('owner', self::KEY)->willReturn($row);
	}

	public function testARepeatedTickCallCountsOnce(): void {
		$this->givenLink();
		$this->counter();
		$this->storedKey(IdempotencyService::fingerprint('ping.tick', [7, null, null, null]), 200, ['value' => 4]);
		$this->links->expects($this->once())->method('touch');
		$this->assertNothingWritten();

		$response = $this->controller()->call(self::TOKEN);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['value' => 4], $response->getData());
	}

	public function testAKeyReusedWithAnotherValueIsRefused(): void {
		$this->givenLink();
		$this->counter();
		$this->storedKey(IdempotencyService::fingerprint('ping.tick', [7, null, null, null]), 200, ['value' => 4]);
		$this->assertNothingWritten();

		$this->assertError($this->controller()->call(self::TOKEN, value: '3'), 422, 'This Idempotency-Key was already used for another request', 'idempotencyKeyReused');
	}

	public function testAKeyThatIsntAUuidIsRefused(): void {
		$this->givenLink();
		$this->headers['Idempotency-Key'] = 'once';
		$this->assertNothingWritten();

		$this->assertError($this->controller()->call(self::TOKEN), 400, 'Idempotency-Key must be a UUID', 'invalidValue');
	}

	public function testAFirstKeyedCallTicksAndItsAnswerIsStored(): void {
		$this->givenLink();
		$this->counter();
		$this->headers['Idempotency-Key'] = self::KEY;
		$claim = new IdempotencyKey();
		$claim->setId(11);
		$this->idempotencyMapper->expects($this->once())->method('claim')
			->with('owner', self::KEY, IdempotencyService::fingerprint('ping.tick', [7, null, null, null]))
			->willReturn($claim);
		$this->idempotencyMapper->expects($this->once())->method('storeAnswer')->with(11, 200, '{"value":1}');
		$this->tickService->expects($this->once())->method('increment')->willReturn(['value' => 1]);

		$this->controller()->call(self::TOKEN);
	}

	public function testAViewCallIgnoresTheKey(): void {
		$this->givenLink('view');
		$this->headers['Idempotency-Key'] = 'not even a UUID';
		$this->idempotencyMapper->expects($this->never())->method('claim');
		$this->tickService->method('findByDateRange')->willReturn([]);

		$this->assertSame(200, $this->controller()->call(self::TOKEN)->getStatus());
	}

	// --- View only ---

	private function tick(string $date, int $value = 1): Tick {
		$tick = new Tick();
		$tick->setId(900);
		$tick->setTrackId(3);
		$tick->setDate($date);
		$tick->setValue($value);
		return $tick;
	}

	public function testAPlainViewCallAnswersTodaysTick(): void {
		$this->givenLink('view');
		$this->tickService->expects($this->once())->method('findByDateRange')
			->with('owner', self::TODAY, self::TODAY, 3)
			->willReturn([$this->tick(self::TODAY)]);
		$this->noteService->expects($this->never())->method('findByDateRange');

		$response = $this->controller()->call(self::TOKEN);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['ticks' => [['id' => 900, 'trackId' => 3, 'date' => self::TODAY, 'value' => 1]]], $response->getData());
	}

	public function testAnUntickedDayIsAnEmptyList(): void {
		$this->givenLink('view');
		$this->tickService->method('findByDateRange')->willReturn([]);

		$this->assertSame(['ticks' => []], $this->controller()->call(self::TOKEN)->getData());
	}

	public function testARangeWithNotes(): void {
		$this->givenLink('view', 'oneWeek');
		$this->tickService->expects($this->once())->method('findByDateRange')
			->with('owner', '2026-11-10', self::TODAY, 3)
			->willReturn([$this->tick('2026-11-14', 2), $this->tick('2026-11-11')]);
		$note = new Note();
		$note->setTrackId(3);
		$note->setDate('2026-11-14');
		$note->setText('Backup OK');
		$this->noteService->expects($this->once())->method('findByDateRange')
			->with('owner', '2026-11-10', self::TODAY, 3)
			->willReturn([$note]);

		$data = $this->controller()->call(self::TOKEN, from: '2026-11-10', to: self::TODAY, includeNotes: 'true')->getData();

		$this->assertSame(['2026-11-14', '2026-11-11'], array_column($data['ticks'], 'date'));
		$this->assertSame([['trackId' => 3, 'date' => '2026-11-14', 'text' => 'Backup OK']], $data['notes']);
	}

	public function testIncludeNotesFalseLeavesThemOut(): void {
		$this->givenLink('view');
		$this->tickService->method('findByDateRange')->willReturn([]);
		$this->noteService->expects($this->never())->method('findByDateRange');

		$this->assertSame(['ticks' => []], $this->controller()->call(self::TOKEN, includeNotes: 'no')->getData());
	}

	public static function badViewProvider(): array {
		return [
			'includeNotes unreadable' => [['includeNotes' => 'maybe'], 'includeNotes must be true or false'],
			'date with from' => [['date' => self::TODAY, 'from' => self::TODAY], 'Send date, or from and to, not both'],
			'date with to' => [['date' => self::TODAY, 'to' => self::TODAY], 'Send date, or from and to, not both'],
			'only from' => [['from' => '2026-11-15'], 'From and to must be dates as YYYY-MM-DD'],
			'only to' => [['to' => '2026-11-15'], 'From and to must be dates as YYYY-MM-DD'],
			'from after to' => [['from' => self::TODAY, 'to' => '2026-11-15'], 'From must be on or before to'],
			'bad date' => [['date' => '16.11.2026'], 'Date must be a date as YYYY-MM-DD'],
			'value' => [['value' => '1'], "View only links don't tick: leave out value, appendNote and the body"],
			'appendNote' => [['appendNote' => 'x'], "View only links don't tick: leave out value, appendNote and the body"],
		];
	}

	/**
	 * @dataProvider badViewProvider
	 */
	public function testABadViewCallIsRefused(array $parameters, string $message): void {
		$this->givenLink('view');
		$this->tickService->expects($this->never())->method('findByDateRange');

		$this->assertError($this->controller()->call(self::TOKEN, ...$parameters), 400, $message, 'invalidValue');
	}

	public function testAViewLinkRefusesATextBody(): void {
		$this->givenLink('view');
		$this->postText('Backup OK');
		$this->tickService->expects($this->never())->method('findByDateRange');

		$this->assertError($this->controller()->call(self::TOKEN), 400, "View only links don't tick: leave out value, appendNote and the body", 'invalidValue');
	}

	public function testARangePartlyOutsideTheAllowedDaysAnswersNothing(): void {
		$this->givenLink('view', 'oneWeek');
		$this->tickService->expects($this->never())->method('findByDateRange');

		$this->assertError($this->controller()->call(self::TOKEN, from: '2026-11-09', to: self::TODAY), 403,
			"This link's allowed days (1 week) don't include every day from 2026-11-09 to 2026-11-16", 'dayNotAllowed');
	}

	public function testAViewOfTomorrowIsRefused(): void {
		$this->givenLink('view', 'allDays');
		$this->tickService->expects($this->never())->method('findByDateRange');

		$this->assertError($this->controller()->call(self::TOKEN, date: '2026-11-17'), 403, "This link's allowed days (All) don't include 2026-11-17", 'dayNotAllowed');
	}
}
